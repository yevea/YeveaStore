#!/bin/bash
# =====================================================================
# Informe DIARIO de visitas de bots de IA / buscadores — yevea.com
#
# - Analiza el tráfico de AYER en los access-logs.
# - Guarda el informe en MyFiles/yeveastore-reports/YYYY-MM-DD.txt
#   (el dashboard de Admin → YeveaStore los muestra desde ahí).
# - Envía el informe por email como TEXTO PLANO (sin adjuntos), salvo
#   que esté desactivado en Admin → YeveaStore → "Informe por email".
#
# Cron (diario 08:00):
#   0 8 * * * /bin/bash $HOME/public_html/cat/Plugins/YeveaStore/Scripts/ai-bot-report.sh
# =====================================================================

EMAIL="martin@yevea.com"
FS_DIR="$HOME/public_html/cat"
OUT_DIR="$FS_DIR/MyFiles/yeveastore-reports"
LOGS="$HOME/access-logs/yevea.com $HOME/access-logs/yevea.com-ssl_log"

# Crawlers de IA (entrenamiento y búsqueda en vivo) + buscadores clásicos
BOTS='GPTBot|OAI-SearchBot|ChatGPT-User|ClaudeBot|Claude-User|Claude-Web|anthropic-ai|PerplexityBot|Perplexity-User|Google-Extended|GoogleOther|CCBot|Bytespider|Amazonbot|Applebot|meta-externalagent|FacebookBot|cohere-ai|MistralAI|bingbot|Googlebot'

DAY_LOG=$(date -d yesterday '+%d/%b/%Y')   # formato de fecha en el log: 04/Jul/2026
STAMP=$(date -d yesterday '+%F')           # 2026-07-04

mkdir -p "$OUT_DIR"

REPORT=$(
    echo "Informe diario de bots de IA y buscadores — yevea.com"
    echo "Dia analizado: $STAMP"
    echo

    total_all=0
    for f in $LOGS; do
        [ -f "$f" ] || continue
        day_lines=$(grep "\[$DAY_LOG" "$f" 2>/dev/null | grep -Ei "$BOTS")
        count=$(echo -n "$day_lines" | grep -c . 2>/dev/null)
        total_all=$((total_all + count))

        echo "== $(basename "$f") — $count visitas de bots =="
        if [ "$count" -gt 0 ]; then
            echo
            echo "--- Visitas por bot ---"
            echo "$day_lines" | grep -oEi "$BOTS" | sort | uniq -ci | sort -rn
            echo
            echo "--- Top 15 paginas pedidas por bots de IA (sin buscadores clasicos) ---"
            echo "$day_lines" | grep -vEi 'bingbot|Googlebot/' | awk '{print $7}' | sort | uniq -c | sort -rn | head -15
        fi
        echo
    done

    echo "Total del dia: $total_all visitas de bots"
    echo
    echo "Leyenda: GPTBot/OAI-SearchBot/ChatGPT-User = OpenAI · ClaudeBot/Claude-User = Anthropic"
    echo "PerplexityBot = Perplexity · Google-Extended = Gemini · CCBot = Common Crawl (entrenamiento)"
    echo
    echo "Si un bot de IA no aparece nunca, sigue bloqueado por el proxy anti-bot del hosting."
)

# Guardar para el dashboard y rotar informes de mas de 60 dias
echo "$REPORT" > "$OUT_DIR/$STAMP.txt"
find "$OUT_DIR" -name "*.txt" -mtime +60 -delete 2>/dev/null

# ¿Email desactivado en los ajustes de la tienda?
DBNAME=$(grep -oP "FS_DB_NAME.,\s*.\K[^'\"]+" "$FS_DIR/config.php" | head -1)
DBUSER=$(grep -oP "FS_DB_USER.,\s*.\K[^'\"]+" "$FS_DIR/config.php" | head -1)
DBPASS=$(grep -oP "FS_DB_PASS.,\s*.\K[^'\"]+" "$FS_DIR/config.php" | head -1)
PROPS=$(mysql -u"$DBUSER" -p"$DBPASS" "$DBNAME" -N -e "SELECT properties FROM settings WHERE name='yeveastore';" 2>/dev/null)
if echo "$PROPS" | grep -qE '"bot_report_email"[[:space:]]*:[[:space:]]*("false"|false|"0")'; then
    exit 0
fi

# Email en texto plano (sendmail con cabeceras explicitas: nunca llega como adjunto)
/usr/sbin/sendmail -t <<EOF
To: $EMAIL
Subject: Yevea: informe diario bots IA ($STAMP)
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit

$REPORT
EOF
