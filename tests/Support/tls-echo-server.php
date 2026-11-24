<?php

declare(strict_types=1);

/*
 * A loopback TLS server for the client certificate test, run as a child process:
 *
 *     php tls-echo-server.php <server certificate> <server key> <client CA> <lifetime seconds>
 *
 * It listens on 127.0.0.1 with a port chosen by the system and prints "PORT <n>" on its first
 * line. It requires a client certificate signed by the given CA and answers each request with
 * HTTP 200 and the common name of the certificate the client presented. It exits after the
 * lifetime, whatever happens to the test.
 */

[$certificate, $key, $ca, $lifetime] = array_slice($argv, 1) + [null, null, null, '10'];

$context = stream_context_create(['ssl' => [
    'local_cert' => $certificate,
    'local_pk' => $key,
    'cafile' => $ca,
    'verify_peer' => true,
    'verify_peer_name' => false,
    'allow_self_signed' => false,
    'capture_peer_cert' => true,
]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, 'PORT '.substr($name, strrpos($name, ':') + 1)."\n");

$deadline = time() + (int) $lifetime;
while (time() < $deadline) {
    $connection = @stream_socket_accept($server, 1.0);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 5);
    $request = '';
    while (! str_contains($request, "\r\n\r\n") && ! feof($connection)) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    $peer = stream_context_get_params($connection)['options']['ssl']['peer_certificate'] ?? null;
    $parsed = $peer === null ? false : openssl_x509_parse($peer);
    $commonName = is_array($parsed) ? (string) ($parsed['subject']['CN'] ?? '') : '';
    if ($commonName === '') {
        fclose($connection);

        continue;
    }
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: ".strlen($commonName)."\r\nConnection: close\r\n\r\n".$commonName);
    fclose($connection);
}
