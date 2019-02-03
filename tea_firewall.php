<?php

cli_set_process_title(
	sprintf("%s %s --daemonize", PHP_BINARY, __FILE__)
);

is_dir("/var/run/tea_firewall") or mkdir("/var/run/tea_firewall");
file_put_contents("/var/run/tea_firewall/tea_firewall.pid", getmypid());

$shmKey = [
	ftok(__FILE__, 'q'),
	ftok(__FILE__, 'm')
];

define("SHMOP_SIZE", 1024 * 1024 * 10);

if (!($workerPid = pcntl_fork())) {
	
	cli_set_process_title("worker: drop-log-parser");

	require __DIR__."/config.php";
	require __DIR__."/shmop_helpers.php";

	$f = "";
	$ldir = sprintf("%s/ld", STORAGE_DIR);
	is_dir(STORAGE_DIR) or mkdir(STORAGE_DIR);
	is_dir($ldir) or mkdir($ldir);

	$handle = proc_open(
		"exec dmesg -w", 
		[
			["pipe", "r"],
			["pipe", "w"],
			["pipe", "w"]
		],
		$pipes
	);

	while (is_resource($handle)) {
		$line = fgets($pipes[1]);
		printf("drop-log-parser...\n");
		if (preg_match("/(?:\[INPUT_LOG:DROP\].+SRC=)((?:\d{1,3}\.){3}\d{1,3})/USsi", $line, $m)) {
			printf("Match!\n");
			var_dump($m);
			$f = sprintf("%s/%s", $ldir, $m[1]);
			
			if (file_exists($f)) {
				$n = sprintf("%d", ((int)file_get_contents($f)) + 1);
			} else {
				$n = "1";
			}

			if (((int)$n) >= MAX_DROP_FAILS) {
				printf("Got max drop!\n");
				$shmid = shmop_open($shmKey[0], "c", 0600, SHMOP_SIZE);
				$curData = str_from_mem(shmop_read($shmid, 0, SHMOP_SIZE));
				var_dump($curData);
				$r = json_decode($curData, true);
				var_dump($r);
				if (is_array($r)) {
					$r[] = $m[1];
					$curData = $r;
				} else {
					$curData = [$m[1]];
				}
				unset($r);
				$curData = array_unique($curData);
				shmop_write($shmid, str_to_nts(json_encode($curData)), 0);
				shmop_close($shmid);
			}

			file_put_contents($f, $n);
		}
		unset($line, $m, $f, $n);
		sleep(SLEEP_PER_LINE);
	}

	proc_close($handle);

	exit(0);
}


if (!($blockerPid = pcntl_fork())) {

	require __DIR__."/shmop_helpers.php";

	shell_exec("iptables -N TEA_FIREWALL >> /dev/null 2>&1");
	shell_exec("iptables -A TEA_FIREWALL -j RETURN");
	while (true) {
		$shmid = shmop_open($shmKey[0], "c", 0600, SHMOP_SIZE);
		$curData = str_from_mem(shmop_read($shmid, 0, SHMOP_SIZE));
		
		printf("Blocker...\n");

		$r = json_decode($curData, true);
		$curData = $r;
		var_dump($r);

		shell_exec("iptables -D TEA_FIREWALL -j RETURN");
		printf("iptables -D TEA_FIREWALL -j RETURN\n");
		foreach ($curData as $ip) {
			
			$a = sprintf("iptables -D TEA_FIREWALL -s %s -j DROP", $ip);
			$b = sprintf("iptables -A TEA_FIREWALL -s %s -j DROP", $ip);

			printf("%s\n%s\n", $a, $b);

			shell_exec($a);
			shell_exec($b);
		}
		printf("iptables -A TEA_FIREWALL -j RETURN\n");
		shell_exec("iptables -A TEA_FIREWALL -j RETURN");
		unset($r);

		shmop_write($shmid, str_to_nts(json_encode($curData)), 0);
		shmop_close($shmid);
		sleep(3);
	}
	exit(0);
}





$status = null;
pcntl_wait($status);
