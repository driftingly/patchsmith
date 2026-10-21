#!/bin/bash
# Read-only PHP audit for Forge servers. Run as root.
set -u
HOST=$(hostname)
echo "================ $HOST ================"

echo "-- OS --"
. /etc/os-release; echo "$PRETTY_NAME"
grep -rhs '^deb .*ondrej/php' /etc/apt/sources.list /etc/apt/sources.list.d/ | head -1 \
  || grep -rls 'ondrej/php' /etc/apt/sources.list.d/ || echo "WARNING: ondrej/php PPA not found"

echo "-- Refreshing package lists --"
if timeout 180 apt-get update -qq -o DPkg::Lock::Timeout=120 >/dev/null 2>&1; then
  APT_OK=yes; echo ok
else
  APT_OK=no; echo "apt-get update FAILED (available-updates list may be stale)"
fi

echo "-- Installed PHP binaries --"
VERSIONS=""
for p in /usr/bin/php[0-9].[0-9]; do
  [ -x "$p" ] || continue
  v=$("$p" -r 'echo PHP_VERSION;')
  printf "%s: %s\n" "$(basename "$p")" "$v"
  VERSIONS="$VERSIONS $v"
done

echo "-- CLI default (php) --"
php -r 'echo PHP_VERSION, PHP_EOL;'

echo "-- FPM services --"
systemctl list-units 'php*-fpm*' --type=service --all --no-legend --no-pager

echo "-- PHP packages not from apt (Forge cannot patch these) --"
for p in /usr/bin/php[0-9].[0-9]; do
  [ -x "$p" ] && ! dpkg -S "$p" >/dev/null 2>&1 && echo "$p"
done
echo "(end)"

echo "-- Sites -> PHP-FPM socket --"
grep -Hs 'fastcgi_pass' /etc/nginx/sites-enabled/* \
  | sed -E 's#^/etc/nginx/sites-enabled/([^:]+):.*unix:([^;]+);.*#\1 -> \2#' | sort -u

echo "-- Daemons / queue workers (supervisor) --"
grep -Hs '^command=' /etc/supervisor/conf.d/*.conf | sed -E 's#^/etc/supervisor/conf.d/##'

echo "-- Scheduler / cron (forge user) --"
crontab -u forge -l 2>/dev/null | grep -v '^#' | grep -i php || echo "none"

echo "-- Running PHP processes on a replaced (stale) binary --"
STALE=0
for pid in $(pgrep -f 'php'); do
  exe=$(readlink "/proc/$pid/exe" 2>/dev/null) || continue
  case "$exe" in
    *php*"(deleted)"*) STALE=$((STALE+1))
      echo "PID $pid: $exe :: $(tr '\0' ' ' < /proc/$pid/cmdline | cut -c1-120)";;
  esac
done
[ "$STALE" -eq 0 ] && echo "none"

echo "-- PHP package updates available --"
UPG=$(apt list --upgradable 2>/dev/null | grep -E '^php[0-9]\.[0-9]-' | cut -d/ -f1)
if [ -n "$UPG" ]; then
  apt list --upgradable 2>/dev/null | grep -E '^php[0-9]\.[0-9]-(cli|fpm)/'
  echo "($(echo "$UPG" | wc -l) php packages upgradable in total, incl. extensions)"
else
  echo "none"
fi

echo "-- PHP outside apt/Forge (other locations) --"
FOUND=$(nice -n 10 ionice -c3 find /usr/local /opt /home /root /srv -xdev \
  \( -path '*/node_modules' -o -path '*/vendor' -o -path '*/.git' -o -path '*/storage' \) -prune -o \
  -type f -perm -u+x \( -name 'php' -o -name 'php[0-9]*' -o -name 'php-fpm*' -o -name 'frankenphp' \) \
  -print 2>/dev/null)
for f in $FOUND; do
  dpkg -S "$f" >/dev/null 2>&1 && continue
  echo "$f  (owner: $(stat -c %U "$f"), modified: $(stat -c %y "$f" | cut -d' ' -f1))"
done
[ -z "$FOUND" ] && echo "none"

echo "-- Running PHP-like processes by binary --"
for pid in $(pgrep -f 'php|frankenphp'); do
  readlink "/proc/$pid/exe" 2>/dev/null
done | sort | uniq -c

echo "-- Docker containers --"
command -v docker >/dev/null && docker ps --format '{{.Names}}  {{.Image}}' 2>/dev/null || echo "docker not installed"

echo "SUMMARY | $HOST | $PRETTY_NAME | php:${VERSIONS# } | apt_update:$APT_OK | upgradable:$(echo -n "$UPG" | grep -c . ) | stale_procs:$STALE"
