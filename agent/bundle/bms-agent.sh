#!/bin/sh
# Runs the BMS agent with the Python that comes in this bundle, for a machine that has none.
# Use it exactly as you would use "python3 bms_agent.py", for example:
#   sudo BMS_AGENT_TOKEN='the-secret' ./bms-agent.sh --install --url https://bms.example.com --location nas-main=/data/backups
#   sudo ./bms-agent.sh --upgrade
#   ./bms-agent.sh --version
# The first time it is run as root it copies the bundled Python to /opt/bms-python (or $BMS_PYTHON_DIR) so the service has a permanent
# interpreter and the folder you unpacked this into can be deleted afterwards.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
prefix=${BMS_PYTHON_DIR:-/opt/bms-python}
py="$here/python/bin/python3"

if [ "$(id -u)" = 0 ] && [ ! -x "$prefix/bin/python3" ]; then
    echo "Copying the bundled Python to $prefix ..." >&2
    mkdir -p "$prefix"
    cp -a "$here/python/." "$prefix/"
fi
[ -x "$prefix/bin/python3" ] && py="$prefix/bin/python3"
[ -x "$py" ] || { echo "The bundled Python is missing from $here/python. Unpack the whole bundle again." >&2; exit 1; }

exec "$py" "$here/bms_agent.py" "$@"
