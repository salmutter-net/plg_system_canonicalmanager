<?php

$root = dirname(__DIR__);
$manifest = simplexml_load_file($root . '/canonicalmanager.xml');
$updates = simplexml_load_file($root . '/update.xml');
if (!$manifest || !$updates || (string) $manifest->version !== (string) $updates->update->version) {
    throw new RuntimeException('Manifest and update versions must match.');
}
foreach ($manifest->files->children() as $file) {
    if (!file_exists($root . '/' . (string) $file)) {
        throw new RuntimeException('Missing packaged file: ' . $file);
    }
}
$referenceKeys = null;
foreach (['en-GB', 'de-DE'] as $language) {
    foreach (['ini', 'sys.ini'] as $suffix) {
        $file = $root . '/language/' . $language . '/plg_system_canonicalmanager.' . $suffix;
        $strings = parse_ini_file($file, false, INI_SCANNER_RAW);
        if (!$strings) {
            throw new RuntimeException('Invalid language file: ' . $file);
        }
        if ($suffix === 'ini') {
            $keys = array_keys($strings);
            sort($keys);
            if ($referenceKeys !== null && $keys !== $referenceKeys) {
                throw new RuntimeException('Translation keys differ.');
            }
            $referenceKeys = $keys;
        }
    }
}
echo "Manifest, update metadata, packaged paths and translations validated.\n";
