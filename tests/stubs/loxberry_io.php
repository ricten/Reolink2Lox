<?php
// Attrappe von loxberry_io.php (nur fuer Tests): Verbindungsdaten wie von mqtt_connectiondetails().
function mqtt_connectiondetails()
{
    return ['brokerhost' => '127.0.0.1', 'brokerport' => 1883, 'brokeruser' => '', 'brokerpass' => '',
        'udpinport' => getenv('STUB_UDP') ?: '11884', 'brokeraddress' => '127.0.0.1:1883', 'tls' => false];
}
