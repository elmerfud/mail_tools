#!/usr/bin/php
<?php

require_once 'mail_copy.inc.php';
require_once 'Console/CommandLine.php';
require_once 'MDB2.php';

error_reporting(E_ALL & ~E_DEPRECATED ^ E_NOTICE);

$cmd_line = new Console_CommandLine();
$cmd_line->description = "POP3 to IMAP INBOX copy";
$cmd_line->version = '0.01 alpha';

$cmd_line->addOption('user_pass_file', array(
    'short_name' => '-f',
    'long_name' => '--file',
    'description' => 'Username & Password file',
    'help_name' => 'FILE',
    'action' => 'StoreString'));
$cmd_line->addOption('noop', array(
    'short_name' => '-n',
    'long_name' => '--noop',
    'description' => 'Do no actual message copy work',
    'help_name' => 'NOOP',
    'action' => 'StoreTrue'));
$cmd_line->addOption('process_forks', array(
    'short_name' => '-p',
    'long_name' => '--pfork',
    'description' => 'Number of processes to fork off',
    'help_name' => 'PFORK',
    'default' => 5,
    'action' => 'StoreInt')); 
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

} catch (Exception $cmd_exc) {
    $cmd_line->displayError($cmd_exc->getMessage());
}

// File containing usernames & passwords space seperated one per line
$user_pass_file =& $cmd_res->options['user_pass_file'];
$forks =& $cmd_res->options['process_forks']; // how many children to spawn
$msg_id = msg_get_queue(55555); // IPC message queue
$_noop =& $cmd_res->options['noop'];
$_verb =& $cmd_res->options['verbose'];

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
        // Setup DB connection in Child
        $mdb2 =& MDB2::connect($config['db_dsn'],array('debug' => 2, 'portability' => MDB2_PORTABILITY_ALL));
        if (PEAR::isError($mdb2)) {
            die($mdb2->getMessage);
        }

        //Prep SQL Queries in Child
        $sql_select = "SELECT message_id FROM messages WHERE email_account = ?";
        $sth_select = $mdb2->prepare($sql_select);
        $sql_insert = "INSERT INTO messages (email_account, message_id, uid, seen, udate, copied_on) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE copied_on = NOW()";
        $sth_insert = $mdb2->prepare($sql_insert);


        while (msg_receive($msg_id,0, $msg_type, 4096, $msg, true,MSG_IPC_NOWAIT)) {
            // Get IPC message and do stuff
            $user_creds = explode(" ",$msg);

            // Connect to Source and Destination //
            $src_server = $config['src_servers'][array_rand($config['src_servers'])];
            if ($_verb) { echo "Open Source {$src_server}\n"; }
            $src_mbox = @imap_open("{{$src_server}:110/pop3}",$user_creds[0],$user_creds[1]);
            if ($src_mbox === false) {
                if ($_verb) { echo "Fail to connect to source\n"; }
                //continue;
            } else {
                $dst_server = $config['dst_servers'][array_rand($config['dst_servers'])];
                if ($_verb) { echo "Open Destination {$dst_server}\n"; }
                $dst_mbox = @imap_open("{{$dst_server}:143/imap}",$user_creds[0],$user_creds[1]);
                if ($dst_mbox === false) {
                    die("failed to connect to destination\n");
                }

                if ($config['clear_destination']===true) {
                    $dst_mbox_check = imap_check($dst_mbox);
                    if ($dst_mbox_check->Nmsgs > 0) {
                        // Delete and expunge messages
                        if ($_noop!==true) {
                            imap_delete($dst_mbox,'1:*');
                        }
                    }
                    if ($_noop!==true) {
                        imap_expunge($dst_mbox);   
                    }
                }

                // Get copy history from SQL
                $res = $sth_select->execute(array($user_creds[0]));
                if ($res->numrows()>=1) {
                    while ($row = $res->fetchRow()) {
                        $src_mbox_message_ids[$row[0]] = false;
                    }
                } else {
                    $src_mbox_message_ids = array();
                }
                // Fetch overview of source mailbox //
                $src_mbox_check = imap_check($src_mbox);
                $src_mbox_over = imap_fetch_overview($src_mbox, "1:" . $src_mbox_check->Nmsgs);

                // Loop source, Determine messages that need copied, and copy //
                foreach ($src_mbox_over as $msg_over) {
                    //$src_mbox_message_ids[$msg_over->message_id] = true;
                    if ($config['do_copy_anyway']===false) {
                        //$res = $sth_select->execute(array($user_creds[0],$msg_over->message_id));
                        if (isset($src_mbox_message_ids[$msg_over->message_id])===true) {
                            // Message has been copied in the past, skipping
                            $src_mbox_message_ids[$msg_over->message_id] = true;
                            continue;
                        }
                    } 
                    $src_mbox_message_ids[$msg_over->message_id] = true;
                    // Find flags
                    $flag_append = '';
                    foreach ($config['flags'] as $flag) {
                        if ($msg_over->{$flag}==1) {
                            $flag_append .= "\\" . ucfirst($flag);
                        }
                    }
                    if ($flag_append == '') {
                        $flag_append = null;
                    }
 
                    // Copy message
                    if ($_noop!==true) {
                        $src_msg_body = imap_fetchbody($src_mbox,$msg_over->msgno,null,FT_PEEK | FT_INTERNAL);
                        if (imap_append($dst_mbox,'{216.81.218.114:143/imap}INBOX',$src_msg_body,$flag_append,date('d-M-Y H:i:s O',$msg_over->udate))) {
                            $sql_data = array($user_creds[0], $msg_over->message_id, $msg_over->uid, $msg_over->seen, $msg_over->udate);
                            $res = $sth_insert->execute($sql_data);
                            if (PEAR::isError($res)) {
                                die("Error inserting in to SQL\n");
                            }
                        } else { 
                            echo "fail {$flag_append}\n";
                        }
                    }
                }
        
                if ($config['attempt_sync']===true) {
       
                    $dst_mbox_check = imap_check($dst_mbox);
                    $dst_mbox_over = imap_fetch_overview($dst_mbox, "1:" . $dst_mbox_check->Nmsgs);
 
                    foreach ($dst_mbox_over as $msg_over) {
                        if (isset($src_mbox_message_ids[$msg_over->message_id])) {
                            if ($src_mbox_message_ids[$msg_over->message_id]===false) {
                                if ($_verb) { echo "Message remove from source, cleaning up\n"; }
                                if ($_noop!==true) {
                                    imap_delete($dst_mbox,$msg_over->msgno);
                                }
                            }
                        }
                    }
                    if ($_noop!==true) {
                        imap_expunge($dst_mbox);
                    }     
                }
            }

            @imap_close($src_mbox);
            @imap_close($dst_mbox);

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
    // send data to workers
    msg_send($msg_id,1,$v);
    if ($cnt % 10 === 0) {
        echo $cnt . "\n";
    }
    $cnt++;
}

// Wait for children
for ($i=1;$i<=$forks;$i++) {
    pcntl_waitpid($pids[$i], $status, WUNTRACED);
}

?>
