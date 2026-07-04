#!/bin/bash
# =====================================================================
# Informe semanal de visitas de bots de IA / buscadores — yevea.com
# Se ejecuta por cron (lunes 08:00) y envía el resumen por email.
# Instalación (crontab):
#   0 8 * * 1 /bin/bash $HOME/public_html/cat/Plugins/YeveaStore/Scripts/ai-bot-report.sh
# =====================================================================

EMAIL="martin@yevea.com"
LOGS="$HOME/access-logs/yevea.com $HOME/access-logs/yevea.com-ssl_log"

# Crawlers de IA (entrenamiento y búsqueda en vivo) + buscadores clásicos
BOTS='GPTBot|OAI-SearchBot|ChatGPT-User|ClaudeBot|Claude-User|Claude-Web|anthropic-ai|PerplexityBot|Perplexity-User|Google-Extended|GoogleOther|CCBot|Bytespider|Amazonbot|Applebot|meta-externalagent|FacebookBot|cohere-ai|MistralAI|bingbot|Googlebot'

REPORT=$(
    echo "Informe de bots de IA y buscadores — yevea.com"
    echo "Generado: $(date '+%d-%m-%Y %H:%M')"
    echo "(logs actuales del mes en curso; los antiguos rotan a .gz)"
    echo

    for f in $LOGS; do
        [ -f "$f" ] || continue
        total=$(grep -c -Ei "$BOTS" "$f" 2>/dev/null)
        echo "==================================================="
        echo "Log: $f — $total visitas de bots"
        echo "==================================================="
        echo
        echo "--- Visitas por bot ---"
        grep -Ei "$BOTS" "$f" | grep -oEi "$BOTS" | sort | uniq -ci | sort -rn
        echo
        echo "--- Top 15 paginas pedidas por bots de IA (sin buscadores clasicos) ---"
        grep -Ei "$BOTS" "$f" | grep -vEi 'bingbot|Googlebot"' \
            | awk '{print $7}' | sort | uniq -c | sort -rn | head -15
        echo
    done

    echo "Leyenda: GPTBot/OAI-SearchBot/ChatGPT-User = OpenAI · ClaudeBot/Claude-User = Anthropic"
    echo "PerplexityBot = Perplexity · Google-Extended = Gemini · CCBot = Common Crawl (entrenamiento)"
    echo
    echo "Si un bot de IA no aparece nunca, sigue bloqueado por el proxy anti-bot."
)

echo "$REPORT" | mail -s "Yevea: informe semanal de bots IA" "$EMAIL"
