#!/bin/bash
#
# ys — despliegue de YeveaStore en producción con un solo comando.
#
# Hace: git pull del plugin + deploy de FacturaScripts (copia a Dinamic,
# crea columnas nuevas, regenera rutas) + limpieza de caché.
#
# Instalación (una sola vez, en el Terminal de cPanel):
#   echo "alias ys='bash ~/public_html/cat/Plugins/YeveaStore/Scripts/ys.sh'" >> ~/.bashrc
#   source ~/.bashrc
#
# Uso: ys
#
# Todo el cuerpo va dentro de main() para que el git pull pueda actualizar
# este mismo archivo sin romper la ejecución en curso.

main() {
    set -e

    echo "→ Actualizando plugin (git pull)…"
    cd ~/public_html/cat/Plugins/YeveaStore
    git pull origin main
    echo "→ Commit desplegado: $(git log --oneline -1)"

    echo "→ Deploy FacturaScripts + limpieza de caché…"
    cd ~/public_html/cat
    php -r 'require "vendor/autoload.php"; const FS_FOLDER=__DIR__; require "config.php"; \FacturaScripts\Core\Kernel::init(); \FacturaScripts\Core\Plugins::deploy(true,true); \FacturaScripts\Core\Cache::clear();'

    echo "✔ Deploy completado. Verifica: https://yevea.com/cat/productos y el admin."
    exit 0
}

main "$@"
