<?php

declare(strict_types=1);

namespace LanSpy\Build;

use SimpleXMLElement;

/** The folder of intermediate files lan-spy.sh writes; missing files read as empty. */
final class ScanDir
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return list<string> the non-empty lines */
    public function lines(string $name): array
    {
        $file = "$this->path/$name";
        return is_file($file) ? preg_split('/\r?\n/', rtrim(file_get_contents($file), "\r\n"), -1, PREG_SPLIT_NO_EMPTY) : [];
    }

    /** @return array<string, string> first column => second column */
    public function tsv(string $name): array
    {
        $out = [];
        foreach ($this->lines($name) as $line) {
            if (str_contains($line, "\t")) {
                [$key, $value] = explode("\t", $line, 2);
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @return list<string> whitespace-separated words */
    public function words(string $name): array
    {
        $file = "$this->path/$name";
        return is_file($file) ? preg_split('/\s+/', trim(file_get_contents($file)), -1, PREG_SPLIT_NO_EMPTY) : [];
    }

    /** @return list<SimpleXMLElement> the <host> elements of an nmap XML file */
    public function nmapHosts(string $name): array
    {
        $file = "$this->path/$name";
        libxml_use_internal_errors(true);
        $xml = is_file($file) ? simplexml_load_file($file) : false;
        libxml_clear_errors();
        return $xml === false ? [] : iterator_to_array($xml->host, false);
    }
}
