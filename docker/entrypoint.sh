#!/bin/sh

toupper() {
  echo "$1" | awk '{ print toupper($0) }'
}

log_header() {

  echo "
==================================================================
      $(toupper "$1")
=================================================================="
}

hangout() {
  log_header 'UPMATH LATEX RENDERER'
}

main() {
  hangout
  umask 0027
  exec /usr/bin/supervisord -c /etc/superv.conf
}

main
