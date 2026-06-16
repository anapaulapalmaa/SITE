<?php
/**
 * Arch3 — carregamento de configuração.
 *
 * A configuração real (arch3-config.php) vive FORA do diretório público.
 * Em produção (HostGator) os endpoints ficam em public_html/api/ e a lib em
 * public_html/api/lib/, então o config fica em /home/<user>/arch3-config.php.
 * Tentamos vários caminhos para funcionar também em dev local.
 */

function arch3_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $candidates = [
        __DIR__ . '/../../../arch3-config.php', // public_html/api/lib -> home
        __DIR__ . '/../../arch3-config.php',    // fallback (mesma profundidade dos endpoints)
        __DIR__ . '/../arch3-config.php',       // php-backend/arch3-config.php (dev local, gitignored)
        getenv('ARCH3_CONFIG') ?: '',
    ];

    foreach ($candidates as $path) {
        if ($path && is_file($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                $cfg = $loaded;
                return $cfg;
            }
        }
    }

    $cfg = [];
    return $cfg;
}

function cfg(string $key, $default = null)
{
    $c = arch3_config();
    return $c[$key] ?? $default;
}
