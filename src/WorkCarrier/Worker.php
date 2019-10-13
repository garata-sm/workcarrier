<?php

namespace WorkCarrier;

/**
 * Fork based Daemon class. Extend this class to create a new daemon. A basic daemon can be created by just calling
 * two methods in your parent class: {@link daemonize}, and {@link fork}.
 * Call the {@link work} method to start the daemon.
 *
 * <br/>
 * The Daemon uses a callback architecture to make it easy for users to schedule their own functionality to the daemon
 * without having to restort to overriding various internal methods.
 *
 * <br/>
 * <b>Client code example usage</b>
 * require_once __DIR__ . '/src/WorkCarrier/Worker.php';
 * $testCallback = function() {
 *   $sleepTime = mt_rand(1, 5);
 *   sleep($sleepTime);
 *   echo "Hello from worker " . getmypid() . "\n";
 * };
 * $worker = new \WorkCarrier\Worker($testCallback);
 * $worker->daemonize('test.pid');
 * $worker->fork(5, true);
 * $worker->work();
 *
 * @author Giorgio Arata <g.arata@siapmicros.com>
 */
class Worker
{
    protected $fork = false;
    protected $daemonize = false;
    protected $callback;
    protected $isChild = false;
    protected $isDaemon = false;
    protected $pidFile;
    protected $pid;
    protected $refork;
    protected $childPids = [];
    
	public function __construct(callable $callback)
    {
        $this->callback = $callback;
        $this->pid = getmypid();
    }
    
	public function fork($num, $refork = false)
    {
        $this->fork = $num;
        $this->refork = $refork;
    }
    
	public function daemonize($pidfile)
    {
        $this->daemonize = true;
        $this->pidFile = $pidfile;
    }
    
	public function work()
    {
		switch((int)$this->daemonize) {
			case 0: FORK:
				$this->childPids = $pids = [];
				goto RE_FORK;
			case 1: DAMONIZE:
				$pid = pcntl_fork();
				if ($pid == 0) {
					$this->pid = getmypid();
					$this->isDaemon = true;
					pcntl_signal(SIGTERM, function() {
						//echo "Daemon process caught SIGTERM\n";
						if ($this->childPids) {
							foreach (array_keys($this->childPids) as $pid) {
								//echo "Sending SIGKILL to child $pid\n";
								posix_kill($pid, SIGKILL);
							}
						}
						//echo "Daemon process $this->pid exiting\n";
						unlink($this->pidFile);
						exit();
					});
				} else {
					//echo "Successfully daemonized. PID $pid written to $this->pidFile\n";
					file_put_contents($this->pidFile, $pid);
					exit();
				}
				if ($this->fork)
					goto FORK;
				break;
            case 2: RE_FORK:
				for ($c = count($this->childPids); $c < $this->fork; $c++) {
					$pid = pcntl_fork();
					if ($pid > 0) {
						// "Forked child with PID $pid\n";
						$this->childPids[$pid] = time();
					} else {
						$this->pid = getmypid();
						$this->isChild = true;
						$this->isDaemon = false;
						goto DO_WORK;
					}
				}
				while (count($this->childPids)) {
					$status = 0;
					$exitedPid = pcntl_wait($status);
					unset($this->childPids[$exitedPid]);
					if ($this->refork)
						goto RE_FORK;
				}
				goto END_FORK;
				break;
			case 3: DO_WORK:
				$func = $this->callback;
				$func();
				exit();
				break;
			case 4: END_FORK:
				exit();
				break;

		}
    }
}
