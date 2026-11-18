<?php

namespace App\Services;

/**
 * Rewrites a PDF that uses cross-reference streams / object streams (PDF 1.5+, e.g. Word 2016)
 * into the classic layout with a plain xref table, which FPDI's free parser can read.
 * Page content is copied byte for byte; only the file structure changes.
 */
class PdfNormalizer
{
    /** @var array<int, array{gen: int, body: string, at: int}> */
    private array $objects = [];

    private string $data = '';

    /** Dictionaries of cross-reference streams (they carry Root/Info/ID instead of a trailer). */
    private array $xrefDicts = [];

    public function normalize(string $source, string $target): bool
    {
        $this->data = (string) file_get_contents($source);
        $this->objects = [];
        $this->xrefDicts = [];

        if ($this->data === '' || preg_match('#/Encrypt\s#', $this->data)) {
            return false;
        }

        $this->readTopLevelObjects();
        $this->unpackObjectStreams();

        $trailer = $this->trailer();
        if (! $trailer || ! isset($trailer['Root'])) {
            return false;
        }

        $this->write($target, $trailer);

        return true;
    }

    private function readTopLevelObjects(): void
    {
        preg_match_all('/(?<![\d.])(\d+)\s+(\d+)\s+obj\b/', $this->data, $matches, PREG_OFFSET_CAPTURE);
        $skipUntil = 0;

        foreach ($matches[0] as $i => [$header, $offset]) {
            if ($offset < $skipUntil) {
                continue; // inside the previous object's stream data
            }
            $num = (int) $matches[1][$i][0];
            $gen = (int) $matches[2][$i][0];
            $start = $offset + strlen($header);

            if (! preg_match('/\bendobj\b|>>\s*stream(?:\r\n|\n|\r)/', $this->data, $m, PREG_OFFSET_CAPTURE, $start)) {
                break;
            }

            if ($m[0][0] === 'endobj') {
                $end = $m[0][1];
            } else {
                $dataStart = $m[0][1] + strlen($m[0][0]);
                $dict = substr($this->data, $start, $m[0][1] + 2 - $start);
                $length = $this->streamLength($dict);
                $endStream = $length !== null ? strpos($this->data, 'endstream', $dataStart + max(0, $length - 2)) : false;
                if ($endStream === false || ($length !== null && $endStream - $dataStart > $length + 4)) {
                    $endStream = strpos($this->data, 'endstream', $dataStart);
                }
                if ($endStream === false) {
                    break;
                }
                $end = strpos($this->data, 'endobj', $endStream);
                if ($end === false) {
                    break;
                }
            }

            $this->objects[$num] = ['gen' => $gen, 'body' => trim(substr($this->data, $start, $end - $start), "\r\n\0 "), 'at' => $offset];
            $skipUntil = $end;
        }
    }

    private function streamLength(string $dict): ?int
    {
        if (! preg_match('#/Length\s+(\d+)(?:\s+(\d+)\s+R)?#', $dict, $m)) {
            return null;
        }
        if (! isset($m[2])) {
            return (int) $m[1];
        }
        // Indirect length: find "N G obj <int> endobj".
        if (preg_match('/(?<![\d.])'.$m[1].'\s+'.$m[2].'\s+obj\s*(\d+)\s*endobj/', $this->data, $len)) {
            return (int) $len[1];
        }

        return null;
    }

    private function unpackObjectStreams(): void
    {
        foreach ($this->objects as $num => $object) {
            $body = $object['body'];
            if (! preg_match('#/Type\s*/ObjStm\b#', $body)) {
                continue;
            }
            $decoded = $this->streamData($body);
            if ($decoded === null || ! preg_match('#/N\s+(\d+)#', $body, $n) || ! preg_match('#/First\s+(\d+)#', $body, $first)) {
                continue;
            }

            $pairs = preg_split('/\s+/', trim(substr($decoded, 0, (int) $first[1])));
            $entries = [];
            for ($i = 0; $i + 1 < count($pairs) && count($entries) < (int) $n[1]; $i += 2) {
                $entries[] = [(int) $pairs[$i], (int) $pairs[$i + 1]];
            }
            foreach ($entries as $i => [$objNum, $rel]) {
                $from = (int) $first[1] + $rel;
                $to = isset($entries[$i + 1]) ? (int) $first[1] + $entries[$i + 1][1] : strlen($decoded);
                // Newer revisions win: keep whichever definition appears later in the file.
                if (! isset($this->objects[$objNum]) || $this->objects[$objNum]['at'] < $object['at']) {
                    $this->objects[$objNum] = ['gen' => 0, 'body' => trim(substr($decoded, $from, $to - $from)), 'at' => $object['at']];
                }
            }
        }

        foreach ($this->objects as $num => $object) {
            if (preg_match('#/Type\s*/(ObjStm|XRef)\b#', $object['body'], $type)) {
                if ($type[1] === 'XRef') {
                    $this->xrefDicts[$object['at']] = strstr($object['body'], 'stream', true) ?: $object['body'];
                }
                unset($this->objects[$num]);
            }
        }
    }

    private function streamData(string $body): ?string
    {
        if (! preg_match('/stream(?:\r\n|\n|\r)/', $body, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $end = strrpos($body, 'endstream');
        $raw = rtrim(substr($body, $start, ($end === false ? strlen($body) : $end) - $start), "\r\n");

        if (! preg_match('#/FlateDecode#', $body)) {
            return preg_match('#/Filter#', $body) ? null : $raw;
        }
        $out = @gzuncompress($raw);
        if ($out === false) {
            $out = @gzinflate(substr($raw, 2));
        }

        return $out === false ? null : $out;
    }

    /** Root/Info/ID from the last classic trailer or cross-reference stream. */
    private function trailer(): ?array
    {
        $dicts = [];
        if (preg_match_all('/trailer\s*(<<.*?>>)\s*startxref/s', $this->data, $m)) {
            $dicts = array_reverse($m[1]); // newest revision first
        }
        krsort($this->xrefDicts);
        $dicts = array_merge($dicts, array_values($this->xrefDicts));

        $result = [];
        foreach ($dicts as $dict) {
            foreach (['Root', 'Info'] as $key) {
                if (! isset($result[$key]) && preg_match('#/'.$key.'\s+(\d+\s+\d+\s+R)#', $dict, $r)) {
                    $result[$key] = $r[1];
                }
            }
            if (! isset($result['ID']) && preg_match('#/ID\s*(\[[^\]]*\])#', $dict, $r)) {
                $result['ID'] = $r[1];
            }
        }

        return $result ?: null;
    }

    private function write(string $target, array $trailer): void
    {
        ksort($this->objects);
        $max = $this->objects ? max(array_keys($this->objects)) : 0;
        $out = fopen($target, 'wb');
        $pos = fwrite($out, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
        $offsets = [];

        foreach ($this->objects as $num => $object) {
            $offsets[$num] = [$pos, $object['gen']];
            $pos += fwrite($out, $num.' '.$object['gen']." obj\n".$object['body']."\nendobj\n");
        }

        $xref = "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $xref .= isset($offsets[$i])
                ? sprintf("%010d %05d n \n", $offsets[$i][0], $offsets[$i][1])
                : "0000000000 00000 f \n";
        }
        fwrite($out, $xref);
        fwrite($out, 'trailer'."\n".'<< /Size '.($max + 1).' /Root '.$trailer['Root']
            .(isset($trailer['Info']) ? ' /Info '.$trailer['Info'] : '')
            .(isset($trailer['ID']) ? ' /ID '.$trailer['ID'] : '')
            ." >>\nstartxref\n".$pos."\n%%EOF\n");
        fclose($out);
    }
}
