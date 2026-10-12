#!/usr/bin/env php
<?php

/**
 * Build the lan-spy JSON report from the raw scan files lan-spy.sh collects.
 *
 * Usage: build-report.php <scan-dir> <interface> <cidr> <gateway> <hostname> > report.json
 *
 * <scan-dir> holds arp.tsv, discovery.xml, dns.tsv, netbios.tsv, mdns.txt,
 * targets.txt, deep.xml, local-ss.txt and (optionally) deep-retry.xml.
 *
 * This machine cannot scan itself over the LAN, so its MAC, OS and listening
 * ports are read locally (/sys, /etc/os-release and `ss -ltnp` output).
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

exit(new LanSpy\Build\BuildCommand()->run($_SERVER['argv']));
