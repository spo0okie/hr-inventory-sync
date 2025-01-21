#!/bin/sh

#выводит в отдельный файл список дубликатов пользователей, обнаруженных в процессе синхронизации

/bin/cat /var/log/SAPsync.last | /bin/grep Dupes | sed 's/:sap>.*Dupes found/Dupes:/g' | sed 's/<bx://g' | tee /var/log/SAPsync.dupes.last | wc -l > /var/log/SAPsync.dupes
if ! diff /var/log/SAPsync.dupes.last /var/log/SAPsync.dupes.current > /dev/nul; then
	echo "got dupes update"
	cat /var/log/SAPsync.dupes.last > /var/log/SAPsync.dupes.current
fi