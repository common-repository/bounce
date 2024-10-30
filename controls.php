<?php

class BounceControls {

    var $data;
    var $action = false;

    function __construct($data = null) {
        if ($data == null)
            $this->data = stripslashes_deep($_POST['options']);
        else
            $this->data = $data;

        $this->action = $_REQUEST['act'];
    }

    function merge_defaults($defaults) {
        if ($this->data == null)
            $this->data = $defaults;
        else
            $this->data = array_merge($defaults, $this->data);
    }

    function get_value($name, $def = null) {
        if (!isset($this->data[$name])) {
            return $def;
        }
        return $this->data[$name];
    }

    /**
     * Return true is there in an asked action is no action name is specified or
     * true is the requested action matches the passed action.
     * Dies if it is not a safe call.
     */
    function is_action($action = null) {
        if ($action == null)
            return $this->action != null;
        if ($this->action == null)
            return false;
        if ($this->action != $action)
            return false;
        if (check_admin_referer())
            return true;
        die('Invalid call');
    }

    /**
     * Show the errors and messages. 
     */
    function show() {
        if (!empty($this->errors)) {
            echo '<div class="error"><p>';
            echo $this->errors;
            echo '</p></div>';
        }
        if (!empty($this->messages)) {
            echo '<div class="updated"><p>';
            echo $this->messages;
            echo '</p></div>';
        }
    }

    function yesno($name) {
        $value = isset($this->data[$name]) ? (int) $this->data[$name] : 0;

        echo '<select style="width: 60px" name="options[' . $name . ']">';
        echo '<option value="0"';
        if ($value == 0)
            echo ' selected';
        echo '>No</option>';
        echo '<option value="1"';
        if ($value == 1)
            echo ' selected';
        echo '>Yes</option>';
        echo '</select>&nbsp;&nbsp;&nbsp;';
    }

    function enabled($name) {
        $value = isset($this->data[$name]) ? (int) $this->data[$name] : 0;

        echo '<select style="width: 100px" name="options[' . $name . ']">';
        echo '<option value="0"';
        if ($value == 0)
            echo ' selected';
        echo '>Disabled</option>';
        echo '<option value="1"';
        if ($value == 1)
            echo ' selected';
        echo '>Enabled</option>';
        echo '</select>';
    }

    function select($name, $options, $first = null) {
        $value = $this->data[$name];

        echo '<select id="options-' . $name . '" name="options[' . $name . ']">';
        if (!empty($first)) {
            echo '<option value="">' . htmlspecialchars($first) . '</option>';
        }
        foreach ($options as $key => $label) {
            echo '<option value="' . $key . '"';
            if ($value == $key)
                echo ' selected';
            echo '>' . htmlspecialchars($label) . '</option>';
        }
        echo '</select>';
    }

    function value($name) {
        echo htmlspecialchars($this->data[$name]);
    }

    function value_date($name) {
        $time = $this->data[$name];
        echo gmdate(get_option('date_format') . ' ' . get_option('time_format'), $time + get_option('gmt_offset') * 3600);
    }

    function text($name, $size = 20) {
        echo '<input name="options[' . $name . ']" type="text" size="' . $size . '" value="';
        echo htmlspecialchars($this->data[$name]);
        echo '"/>';
    }

    function hidden($name) {
        echo '<input name="options[' . $name . ']" type="hidden" value="';
        echo htmlspecialchars($this->data[$name]);
        echo '"/>';
    }

    function button($action, $label, $function = null) {
        if ($function != null) {
            echo '<input class="button-secondary" type="button" value="' . $label . '" onclick="this.form.act.value=\'' . $action . '\';' . htmlspecialchars($function) . '"/>';
        } else {
            echo '<input class="button-secondary" type="button" value="' . $label . '" onclick="this.form.act.value=\'' . $action . '\';this.form.submit()"/>';
        }
    }

    function button_confirm($action, $label, $message, $data = '') {
        echo '<input class="button-secondary" type="button" value="' . $label . '" onclick="this.form.btn.value=\'' . $data . '\';this.form.act.value=\'' . $action . '\';if (confirm(\'' .
        htmlspecialchars($message) . '\')) this.form.submit()"/>';
    }

    function textarea($name, $width = '100%', $height = '50') {
        echo '<textarea class="dymanic" name="options[' . $name . ']" wrap="off" style="width:' . $width . ';height:' . $height . '">';
        echo htmlspecialchars($this->get_value($name));
        echo '</textarea>';
    }

    function textarea_fixed($name, $width = '100%', $height = '50') {
        echo '<textarea name="options[' . $name . ']" wrap="off" style="width:' . $width . ';height:' . $height . '">';
        echo htmlspecialchars($this->data[$name]);
        echo '</textarea>';
    }

    function email($prefix) {
        echo 'Subject:<br />';
        $this->text($prefix . '_subject', 70);
        echo '<br />Message:<br />';
        $this->editor($prefix . '_message');
    }

    function checkbox($name, $label = '') {
        echo '<input type="checkbox" id="' . $name . '" name="options[' . $name . ']" value="1"';
        if (!empty($this->data[$name])) {
            echo ' checked';
        }
        echo '>';
        if ($label != '') {
            echo ' <label for="' . $name . '">' . $label . '</label>';
        }
    }

    function hours($name) {
        $hours = array();
        for ($i = 0; $i < 24; $i++) {
            $hours['' . $i] = '' . $i;
        }
        $this->select($name, $hours);
    }

    function days($name) {
        $days = array(0 => 'Every day', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');
        $this->select($name, $days);
    }

    function init() {
        echo '<script type="text/javascript">
    jQuery(document).ready(function(){
        jQuery("textarea.dynamic").focus(function() {
            jQuery("textarea.dynamic").css("height", "50px");
            jQuery(this).css("height", "400px");
        });
      tabs = jQuery("#tabs").tabs({ cookie: { expires: 30 } });       
    });
</script>
';
        echo '<input name="act" type="hidden" value=""/>';
        echo '<input name="btn" type="hidden" value=""/>';
        wp_nonce_field();
    }

    function button_link($action, $url, $anchor) {
        if (strpos($url, '?') !== false)
            $url .= $url . '&';
        else
            $url .= $url . '?';
        $url .= 'act=' . $action;

        $url .= '&_wpnonce=' . wp_create_nonce();

        echo '<a class="button" href="' . $url . '">' . $anchor . '</a>';
    }

    function print_date($time) {
        echo gmdate(get_option('date_format') . ' ' . get_option('time_format'), $time + get_option('gmt_offset') * 3600);
    }

}
