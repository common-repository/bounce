<?php
include_once BOUNCE_DIR . '/controls.php';
$controls = new BounceControls();

// Force the bounce checking as if it was triggered by hre scheduler
if ($controls->is_action('run')) {
    Bounce::update_options($controls->data);
    if ($controls->data['enabled'] == 1) {
        /* @var $bounce Bounce */
        $bounce->hook_run(true);
        $controls->messages = 'Completed.';
    } else {
        $controls->errors = 'Not ran because not enabled!';
    }
}


// Check for bounces and report the identified emails. No other actions are taken.
if ($controls->is_action('check')) {
    Bounce::update_options($controls->data);

    require_once(ABSPATH . WPINC . '/class-pop3.php');
    $pop3 = new POP3();

    if (!$pop3->connect($controls->data['host'], $controls->data['port']) || !$pop3->user($controls->data['login'])) {
        $controls->errors = 'Unable to connect: ' . $pop3->ERROR;
    } else {

        $count = $pop3->pass($controls->data['password']);

        if (false === $count) {
            $controls->errors = 'Unable to authenticate: ' . $pop3->ERROR;
        } else {
            $controls->messages = 'Messages found: ' . $count . '. See the test tab for more details.';
            $check_result = array();

            // For each message...
            for ($i = 1; $i <= $count; $i++) {

                $message = $pop3->get($i);
                $bodysignal = false;
                foreach ($message as $line) {
                    if (strlen($line) < 3) {
                        $bodysignal = true;
                    }
                    if (!$bodysignal) {
                        continue;
                    }

                    $email = $bounce->check_line($line);
                    if ($email && array_search($email, $check_result) === false) {
                        $check_result[] = $email;
                    }
                }
                if ($bounce->limits_exceeded()) {
                    break;
                }
            }

            // Reset the so it remains in the original status
            $pop3->reset();
        }
    }
}



if ($controls->is_action('save')) {
    if ($controls->data['enabled'] == 1) {
        // Safe check to see if the job was deleted for some reason
        if (!wp_next_scheduled(Bounce::HOOK_RUN)) {
            wp_schedule_event(time() + 30, 'hourly', Bounce::HOOK_RUN);
        }
    }
    $controls->data['emails'] = '';
    Bounce::update_options($controls->data);
    $controls->messages = 'Options saved.';
}


if ($controls->is_action('test')) {
    require_once(ABSPATH . WPINC . '/class-pop3.php');
    $pop3 = new POP3();

    if (!$pop3->connect($controls->data['host'], $controls->data['port']) || !$pop3->user($controls->data['login'])) {
        $controls->errors = 'Unable to connect: ' . $pop3->ERROR;
    } else {

        $count = $pop3->pass($controls->data['password']);

        if (false === $count) {
            $controls->errors = 'Unable to authenticate: ' . $pop3->ERROR;
        } else {
            $controls->messages = 'Connected. Messages found: ' . $count;
            $pop3->reset();
        }
    }
}

if ($controls->is_action('import')) {
    $emails = str_replace(array("\r", "\n", "\t", ','), ' ', $controls->data['emails']);
    $emails = explode(' ', $emails);
    $count = 0;
    if (!empty($emails)) {
        foreach ($emails as &$email) {
            $email = strtolower(trim($email));
            if (!is_email($email)) {
                continue;
            }
            $count++;
            $bounce->bounce($email);
        }
    }

    $controls->messages = 'Processed ' . $count . ' addresses (invalid addresses not counted)';
}

if (empty($controls->data)) {
    $controls->data = Bounce::get_options();
}
?>
<script>
jQuery(document).ready(function(){
    jQuery("#tabs").tabs();
});
</script>
<div class="wrap">

    <h2>Bounce</h2>

    <p>Manages bounced emails sent from this blog. The <a href="http://www.satollo.net/plugins/bounce" target="_blank">official page</a>.</p>

    <p>
        <?php _e('Check out my other useful plugins', 'bounce') ?>:
        <a href="http://www.satollo.net/plugins/comment-plus?utm_source=bounce&utm_medium=link&utm_campaign=comment-plus" target="_blank">Comment Plus</a>,
        <a href="http://www.satollo.net/plugins/hyper-cache?utm_source=bounce&utm_medium=link&utm_campaign=hyper-cache" target="_blank">Hyper Cache</a>,
        <a href="http://www.thenewsletterplugin.com/?utm_source=bounce&utm_medium=link&utm_campaign=newsletter" target="_blank">Newsletter</a>,
        <a href="http://www.satollo.net/plugins/header-footer?utm_source=bounce&utm_medium=link&utm_campaign=header-footer" target="_blank">Header and footer</a>,
        <a href="http://www.satollo.net/plugins/thumbnails?utm_source=bounce&utm_medium=link&utm_campaign=thumbnails" target="_blank">Thumbnails</a>,
        <a href="http://www.satollo.net/plugins/include-me?utm_source=bounce&utm_medium=link&utm_campaign=include-me" target="_blank">Include Me</a>.
    </p>    
    <?php $controls->show(); ?>

    <form method="post" action="">
        <?php $controls->init(); ?>

        <div id="tabs">
            <ul>
                <li><a href="#tabs-general">General</a></li>
                <li><a href="#tabs-mail">Mail Server</a></li>
                <li><a href="#tabs-import">Import</a></li>
                <li><a href="#tabs-test">Test</a></li>
            </ul>

            <div id="tabs-general">    
                <table class="form-table">
                    <tr valign="top">
                        <th>Enabled?</th>
                        <td>
                            <?php $controls->yesno('enabled'); ?>
                        </td>
                    </tr>
                   
                    <tr valign="top">
                        <th>Next run</th>
                        <td>          
                            <?php $controls->print_date(wp_next_scheduled(Bounce::HOOK_RUN)); ?>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th>Return path</th>
                        <td>
                            <?php $controls->text('return_path', 50); ?>
                            <p class="description">
                                A valid email address where error messages should be sent which match the mailbox
                                configured on mail server tab.
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th>WordPress users</th>
                        <td>
                            <?php $controls->select('clean_users', array('0' => 'Do not process', '1' => 'Replace email field', '2' => 'Empty email field')); ?>
                            <p class="description">
                                Should bounce try to clean up the WordPress user table? If you chose to replace the email the @ char is replaced with a
                                # so you can still identify the original wrong email.
                                 </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th>Comments</th>
                        <td>
                            <?php $controls->select('clean_comments', array('0' => 'Do not process', '1' => 'Replace email field', '2' => 'Empty email field')); ?>
                            <p class="description">
                                Should bounce try to clean up the WordPress comment table? If you chose to replace the email the @ char is replaced with a
                                # so you can still identify the original wrong email.
                                 </p>
                        </td>
                    </tr>
                </table>
            </div>



            <div id="tabs-mail">
                <table class="form-table">  
                    <tr>
                        <th>POP host/port</th>
                        <td>
                            host: <?php $controls->text('host', 30); ?>
                            port: <?php $controls->text('port', 6); ?>
                            <p class="description">
                                Use tls:// or ssl:// prefixes on host to change the connection protocol if required.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Authentication</th>
                        <td>
                            user: <?php $controls->text('login', 30); ?>
                            password: <?php $controls->text('password', 30); ?>
                        </td>
                    </tr>      
                </table>
                <?php $controls->button('test', 'Connection test'); ?>

            </div>

            <div id="tabs-import"> 
                <p>
                    Here you can import a list of emails (one per line) which shold be treated as bounced emails. If you
                    user external SMTPs (like <a href="http://www.satollo.net/affieliate/sendgrid" target="_blank">SendGrid</a>)
                    they could provide such list.
                </p>
                
                <?php $controls->textarea('emails'); ?>

                <p><?php $controls->button('import', 'Process addresses'); ?></p>
            </div>


            <div id="tabs-test"> 
                Results:<br>
                <textarea rows="20" cols="100"><?php if (isset($check_result)) echo implode("\n", $check_result); ?></textarea>
                <p>
                    <?php $controls->button_confirm('check', 'Run a test', 'Are you sure?'); ?>
                </p>
            </div>

        </div>

        <p class="submit">
            <?php $controls->button('save', 'Save'); ?>
            <?php $controls->button_confirm('run', 'Run now', 'Are you sure?'); ?>

        </p>

    </form>

</div>