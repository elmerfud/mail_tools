#!/usr/bin/php
<?php

/* Script forks off workers to do connection tests 

   Test method, log in to server.  Request list. Disconnect.

   TODO: option to loop through list multiple times 
         option to randomly choose pop3 or imap
         output connections per second
*/

require_once 'Console/CommandLine.php';

error_reporting(E_ALL ^ E_NOTICE);

$cmd_line = new Console_CommandLine();
$cmd_line->description = "POP3/IMAP Connection Testing";
$cmd_line->version = '0.01 alpha';

$cmd_line->addOption('user_pass_file', array(
    'short_name' => '-f',
    'long_name' => '--file',
    'description' => 'Username & Password file',
    'help_name' => 'FILE',
    'action' => 'StoreString'));
$cmd_line->addOption('process_forks', array(
    'short_name' => '-p',
    'long_name' => '--pfork',
    'description' => 'Number of processes to fork off',
    'help_name' => 'PFORK',
    'default' => 100,
    'action' => 'StoreInt'));
$cmd_line->addOption('server', array(
    'short_name' => '-s',
    'long_name' => '--server',
    'description' => 'List of server(s) IP addresses as comma seperated list',
    'help_name' => 'SERVER',
    'action' => 'StoreString'));
$cmd_line->addOption('verbose', array(
    'short_name' => '-v',
    'long_name' => '--verbose',
    'description' => 'Verbose messages',
    'action' => 'StoreTrue'));

try {
    $cmd_res = $cmd_line->parse();
    if (is_file($cmd_res->options['user_pass_file'])===false) {
        throw new Exception('Option "user_pass_file" requires value to be a file.');
    }

    if (isset($cmd_res->options['server'])===false) {
        throw new Exception('Option "server" requires a value.');
    }

    $srv = parse_server_option($cmd_res->options['server']);
    if (is_array($srv)===false) {
        throw new Exception('Option "server" requires a comma seperated list of IP addresses');
    } 

} catch (Exception $cmd_exc) {
    $cmd_line->displayError($cmd_exc->getMessage());
}

// File containing usernames & passwords space seperated one per line
$user_pass_file =& $cmd_res->options['user_pass_file'];

$forks =& $cmd_res->options['process_forks']; // how many children to spawn
$msg_id = msg_get_queue(55555); // IPC message queue

if (is_file($user_pass_file)===false) {
    die("File {$user_pass_file} not found\n");
}

$child = 0;
$pids = array();
$res_success = 0;
$res_authfail = 0;
$res_otherfail = 0;
// Ok down to bidness
for ($i=1;$i<=$forks;$i++) {
    $child++;
    $pids[$i] = pcntl_fork();
    if (!$pids[$i]) {
        for ($q_s = 0; $q_s <= 13; $q_s++) {
            sleep(1); // wait for IPC queue to get messages
            if (isset($q_stat)) { unset($q_stat); } // unset an old queue status
            $q_stat = msg_stat_queue($msg_id); // get queue status
            if ($q_stat['msg_qnum']!=0) {
                break; //messages here break out!
            }
            if ($q_s==12) { // Been a min and no queue messages time to give up
                exit(0);
            }
        }
        while (msg_receive($msg_id,0, $msg_type, 4096, $msg, true,MSG_IPC_NOWAIT)) {
            // Get IPC message and do stuff
            $z = explode(" ",$msg);
            $r = rand(0,count($srv) - 1);
            $mbox = @imap_open ("{{$srv[$r]}:110/pop3}", $z[0], $z[1]);
            if ($mbox === false) {
                $imap_alerts = imap_alerts();
                $imap_errors = imap_errors();
                if (in_array('authorization failed',$imap_errors)===true) {
                    $res_authfail++;
                } else {
                    $res_otherfail++;
                }
                if ($cmd_res->options['verbose']===true) {
                    echo "ERROR: server: {$srv[$r]} user: {$z[0]} pass: {$z[1]}\n";
                    echo @implode("\n",$imap_alerts) . "\n";
                    echo @implode("\n",$imap_errors) . "\n";
                } 
            } else {
                $check = imap_mailboxmsginfo($mbox);
                //print_r(imap_errors());
                imap_close($mbox);
            }
            if (isset($q_stat)) { unset($q_stat); }
            $q_stat = msg_stat_queue($msg_id);
            if ($q_stat['msg_qnum']==0) { break; } //no messages were done
        }
        exit();
    }
}

$lines = file($user_pass_file,FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$cnt = 0;
foreach ($lines as $v) {
    msg_send($msg_id,1,$v);
    if ($cnt % 300 === 0) {
        echo $cnt . "\n";
    }
    $cnt++;
}

// Wait for children
for ($i=1;$i<=$forks;$i++) {
    pcntl_waitpid($pids[$i], $status, WUNTRACED);
}

function parse_server_option($srv_str)
{
    $e = explode(',',$srv_str);

    foreach ($e as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)===false) {
            return false;
        }
    }
    return $e;
}
?>
