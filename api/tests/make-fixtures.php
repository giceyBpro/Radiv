<?php
// Fabrique les archives ZIP de test (bonne version + variantes piégées) à partir de l'arbre de
// travail du dépôt. Usage: php make-fixtures.php <racine_du_dépôt> <dossier_de_sortie>
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
[$script, $repo, $out] = $argv + [null, null, null];
if (!$repo || !$out) { fwrite(STDERR, "usage\n"); exit(1); }
@mkdir($out, 0777, true);

$tracked = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($repo) . ' && git ls-files -co --exclude-standard')));
$files = [];
foreach ($tracked as $rel) {
    if (is_file("{$repo}/{$rel}")) $files[$rel] = file_get_contents("{$repo}/{$rel}");
}

// $mutate: fonction (array $files): array — modifie l'ensemble des fichiers; $extra: entrées brutes.
function build(string $file, string $root, array $files, array $extra = []): void
{
    @unlink($file);
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE);
    $zip->addEmptyDir($root);
    foreach ($files as $rel => $content) $zip->addFromString("{$root}/{$rel}", $content);
    foreach ($extra as $name => $spec) {
        $zip->addFromString($name, $spec['content'] ?? '');
        if (isset($spec['symlink'])) $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    }
    $zip->close();
}

$sha = static fn (string $c): string => str_repeat($c, 40);
$root = static fn (string $c): string => 'giceyBpro-Radiv-' . substr(str_repeat($c, 40), 0, 7);

// v1.1 (bonne): ajoute un fichier de plus et modifie app.js pour vérifier le remplacement
$good = $files;
$good['app.js'] = ($files['app.js'] ?? '') . "\n// fixture v1.1\n";
$good['downloads/ajout-v1_1.xml'] = '<x/>';
build("{$out}/1.zip", $root('1'), $good);

// v1.2 (bonne): retire downloads/ajout-v1_1.xml, change encore app.js
$good2 = $good; unset($good2['downloads/ajout-v1_1.xml']);
$good2['app.js'] = ($files['app.js'] ?? '') . "\n// fixture v1.2\n";
build("{$out}/2.zip", $root('2'), $good2);

// 3: traversée de chemin
build("{$out}/3.zip", $root('3'), $good, ["{$root('3')}/../../evil.php" => ['content' => '<?php echo 1;']]);
// 4: lien symbolique
build("{$out}/4.zip", $root('4'), $good, ["{$root('4')}/downloads/lien" => ['content' => '/etc/passwd', 'symlink' => true]]);
// 5: erreur de syntaxe PHP
$bad = $good; $bad['api/lib/config.php'] .= "\n function {{{ ";
build("{$out}/5.zip", $root('5'), $bad);
// 6: mauvais dossier racine (ne contient pas le SHA demandé)
build("{$out}/6.zip", 'autre-depot-abcdef0', $good);
// 7: version trop ancienne (sans le code d'administration)
$old = $good; unset($old['api/lib/admin_site.php']);
build("{$out}/7.zip", $root('7'), $old);
// 8: "bombe" : un fichier de 11 Mo (> 10 Mo) très compressible
$bomb = $good; $bomb['downloads/enorme.xml'] = str_repeat('0', 11 * 1024 * 1024);
build("{$out}/8.zip", $root('8'), $bomb);
// 9: syntaxe correcte mais l'API ne répond plus (config renvoie du HTML): retour arrière automatique
$broken = $good;
$broken['api/index.php'] = "<?php\nheader('Content-Type: text/html'); echo '<html>cassé</html>';\n";
build("{$out}/9.zip", $root('9'), $broken);
// 10: fichiers hors liste blanche (.env, .htaccess racine, config.js, api/logs, api/tests): ne doivent jamais être écrits
$extra = $good;
$extra['.env'] = 'ADMIN_TOKEN=pirate'; $extra['.htaccess'] = 'Deny from nobody'; $extra['config.js'] = 'alert(1)';
$extra['vendor/ok.css'] = 'a{}'; $extra['vendor/fonts/ok.woff2'] = 'f'; $extra['vendor/evil.php'] = '<?php'; $extra['vendor/a/b/c.js'] = 'x'; $extra['vendor/.htaccess'] = 'x'; $extra['vendor/fonts/evil.phtml'] = 'x';
$extra['api/.env'] = 'X=1'; $extra['api/logs/x.jsonl'] = 'x'; $extra['api/tests/x.php'] = '<?php'; $extra['deploy.sh'] = 'rm -rf /';
build("{$out}/10.zip", $root('a'), $extra);
echo "ok\n";
