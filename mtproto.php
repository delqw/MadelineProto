<?php
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . DIRECTORY_SEPARATOR . 'libpy2php');
require_once ('libpy2php.php');
require_once ('os_path.php');
require_once ('crypt.php');
require_once ('prime.php');
require_once ('TL.php');
function newcrc32($data) {
    return $originalcrc32($data) & 4294967295;
}
/**
 * Function to visualize byte streams. Split into bytes, print to console.
 * :param bs: BYTE STRING
 */
 define('BIG_ENDIAN', pack('L', 1) === pack('N', 1));
function pack_le($format, $n) {
    if (BIG_ENDIAN) {
        return strrev(pack($format, $n));
    }
    return pack($format, $n);
}
function vis($bs) {
    $bs = bytearray($bs);
    $symbols_in_one_line = 8;
    $n = floor(count($bs) / $symbols_in_one_line);
    $i = 0;
    foreach (pyjslib_range($n) as $i) {
        pyjslib_printnl(pyjslib_str(($i * $symbols_in_one_line)) . ' | ' . ' '->join(
            array_map(function($el) { return "%02X" % $el; }, array_slice($bs,$i*$symbols_in_one_line, ($i+1)*$symbols_in_one_line))
        ));
    }
    if (!(((count($bs) % $symbols_in_one_line) == 0))) {
        pyjslib_printnl(pyjslib_str((($i + 1) * $symbols_in_one_line)) . ' | ' . ' '->join(
            array_map(function($el) { return "%02X" % $el; }, array_slice($bs, ($i+1)*$symbols_in_one_line), null)
        ) . '
');
    }
}
/**
 * bytes_to_long(string) : long
 * Convert a byte string to a long integer.
 * This is (essentially) the inverse of long_to_bytes().
 */
function bytes_to_long($s) {
    $acc = 0;
    $length = count($s);
    if (($length % 4)) {
        $extra = (4 - ($length % 4));
        $s = (($b('') * $extra) + $s);
        $length = ($length + $extra);
    }
    foreach( pyjslib_range(0, $length, 4) as $i ) {
        $acc = ($acc << 32 + unpack('I', array_slice($s, $i, ($i + 4) - $i))[0]);
    }
    return $acc;
}

function fread_all($handle) {
    $pos = ftell($handle);
    fseek($handle, 0);
    $content = fread($handle, fstat($handle)["size"]);
    fseek($handle, $pos);
    return $content;
}

/**
 * long_to_bytes(n:long, blocksize:int) : string
 * Convert a long integer to a byte string.
 * If optional blocksize is given and greater than zero, pad the front of the
 * byte string with binary zeros so that the length is a multiple of
 * blocksize.
 */
function long_to_bytes($n,$blocksize=0) {
    $s = $b('');
    $n = long($n);
    while (($n > 0)) {
        $s = (pack('>I', $n & 4294967295) + $s);
        $n = $n >> 32;
    }
    foreach( pyjslib_range(count($s)) as $i ) {
        if (($s[$i] != $b('')[0])) {
            break;
        }
    }
    $s = array_slice($s, $i, null);
    if (($blocksize > 0) && (count($s) % $blocksize)) {
        $s = ((($blocksize - (count($s) % $blocksize)) * $b('')) + $s);
    }
    return $s;
}
/**
 * Manages TCP Transport. encryption and message frames
 */
class Session {
    function __construct($ip, $port, $auth_key = null, $server_salt = null) {
        $this->sock = fsockopen($ip, $port);
        $this->number = 0;
        $this->timedelta = 0;
        $this->session_id = random_bytes(8);
        $this->auth_key = $auth_key;
        $this->auth_key_id = $this->auth_key ? array_slice(sha1($this->auth_key, true), -8, null) : null;
        stream_set_timeout($this->sock, 5.0);
        $this->MAX_RETRY = 5;
        $this->AUTH_MAX_RETRY = 5;
    }
    function __del__() {
        fclose($this->sock);
    }
    /**
     * Forming the message frame and sending message to server
     * :param message: byte string to send
     */
    function send_message($message_data) {
        $message_id = pack_le('Q', (pyjslib_int(((time() + $this->timedelta) * pow(2, 30))) * 4));
        if (($this->auth_key == null) || ($this->server_salt == null)) {
            $message = '        ' . $message_id . pack_le('I', count($message_data)) . $message_data;
        } else {
            $encrypted_data = (((($this->server_salt + $this->session_id) + $message_id) + pack_le('II', $this->number, count($message_data))) + $message_data);
            $message_key = array_slice(sha1($encrypted_data, true), -16, null);
            $padding = random_bytes((-count($encrypted_data) % 16));
            pyjslib_printnl(count(($encrypted_data + $padding)));
            list($aes_key, $aes_iv) = $this->aes_calculate($message_key);
            $message = (($this->auth_key_id + $message_key) + crypt::ige_encrypt(($encrypted_data + $padding), $aes_key, $aes_iv));
        }
        $step1 = (pack_le('II', (count($message) + 12), $this->number) + $message);
        $step2 = ($step1 + pack_le('I', newcrc32($step1)));
        fwrite($this->sock, $step2);
        $this->number+= 1;
    }
    /**
     * Reading socket and receiving message from server. Check the CRC32.
     */
    function recv_message() {
        $packet_length_data = fread($this->sock, 4);
        if ((count($packet_length_data) < 4)) {
            throw new $Exception('Nothing in the socket!');
        }
        $packet_length = unpack('I', $packet_length_data) [0];
        $packet = fread($this->sock, ($packet_length - 4));
        if (!((newcrc32(($packet_length_data + array_slice($packet, 0, -4 - 0))) == unpack('<I', array_slice($packet, -4, null)) [0]))) {
            throw new $Exception('CRC32 was not correct!');
        }
        $x = unpack('I', array_slice($packet, null, 4));
        $auth_key_id = array_slice($packet, 4, 12 - 4);
        if (($auth_key_id == '        ')) {
            list($message_id, $message_length) = unpack('8sI', array_slice($packet, 12, 24 - 12));
            $data = array_slice($packet, 24, (24 + $message_length) - 24);
        } else if (($auth_key_id == $this->auth_key_id)) {
            $message_key = array_slice($packet, 12, 28 - 12);
            $encrypted_data = array_slice($packet, 28, -4 - 28);
            list($aes_key, $aes_iv) = py2php_kwargs_method_call($this, 'aes_calculate', [$message_key], ["direction" => 'from server']);
            $decrypted_data = crypt::ige_decrypt($encrypted_data, $aes_key, $aes_iv);
            assert((array_slice($decrypted_data, 0, 8 - 0) == $this->server_salt));
            assert((array_slice($decrypted_data, 8, 16 - 8) == $this->session_id));
            $message_id = array_slice($decrypted_data, 16, 24 - 16);
            $seq_no = unpack('I', array_slice($decrypted_data, 24, 28 - 24)) [0];
            $message_data_length = unpack('I', array_slice($decrypted_data, 28, 32 - 28)) [0];
            $data = array_slice($decrypted_data, 32, (32 + $message_data_length) - 32);
        } else {
            throw new $Exception('Got unknown auth_key id');
        }
        return $data;
    }
    function method_call($method, $kwargs) {
        foreach (pyjslib_range(1, $this->MAX_RETRY) as $i) {
            try {
                //var_dump(py2php_kwargs_function_call('serialize_method', [$method], $kwargs));
                $this->send_message(py2php_kwargs_function_call('serialize_method', [$method], $kwargs));
                $server_answer = $this->recv_message();
            }
            catch(Exception $e) {
                echo $e->getMessage();
                pyjslib_printnl('Retry call method');
                continue;
            }
            return deserialize(io::BytesIO($server_answer));
        }
    }
    function create_auth_key() {
        $nonce = random_bytes(16);
        pyjslib_printnl('Requesting pq');
        $ResPQ = py2php_kwargs_method_call($this, 'method_call', ['req_pq'], ["nonce" => $nonce]);
        $server_nonce = $ResPQ['server_nonce'];
        $public_key_fingerprint = $ResPQ['server_public_key_fingerprints'][0];
        $pq_bytes = $ResPQ['pq'];
        $pq = bytes_to_long($pq_bytes);
        list($p, $q) = primefactors($pq);
        if (($p > $q)) {
            list($p, $q) = [$q, $p];
        }
        assert((($p * $q) == $pq) && ($p < $q));
        pyjslib_printnl(sprintf('Factorization %d = %d * %d', [$pq, $p, $q]));
        $p_bytes = long_to_bytes($p);
        $q_bytes = long_to_bytes($q);
        $f = pyjslib_open(os_path::join(os_path::dirname($__file__), 'rsa.pub'));
        $key = RSA::importKey($f->read());
        $new_nonce = random_bytes(32);
        $data = py2php_kwargs_function_call('serialize_obj', ['p_q_inner_data'], ["pq" => $pq_bytes, "p" => $p_bytes, "q" => $q_bytes, "nonce" => $nonce, "server_nonce" => $server_nonce, "new_nonce" => $new_nonce]);
        $sha_digest = sha($data, true);
        $random_bytes = random_bytes(((255 - count($data)) - count($sha_digest)));
        $to_encrypt = (($sha_digest + $data) + $random_bytes);
        $encrypted_data = $key->encrypt($to_encrypt, 0) [0];
        pyjslib_printnl('Starting Diffie Hellman key exchange');
        $server_dh_params = py2php_kwargs_method_call($this, 'method_call', ['req_DH_params'], ["nonce" => $nonce, "server_nonce" => $server_nonce, "p" => $p_bytes, "q" => $q_bytes, "public_key_fingerprint" => $public_key_fingerprint, "encrypted_data" => $encrypted_data]);
        assert(($nonce == $server_dh_params['nonce']));
        assert(($server_nonce == $server_dh_params['server_nonce']));
        $encrypted_answer = $server_dh_params['encrypted_answer'];
        $tmp_aes_key = (sha1(($new_nonce + $server_nonce), true) + array_slice(sha1(($server_nonce + $new_nonce), true), 0, 12 - 0));
        $tmp_aes_iv = ((array_slice(sha1(($server_nonce + $new_nonce), true), 12, 20 - 12) + sha1(($new_nonce + $new_nonce), true)) + array_slice($new_nonce, 0, 4 - 0));
        $answer_with_hash = crypt::ige_decrypt($encrypted_answer, $tmp_aes_key, $tmp_aes_iv);
        $answer_hash = array_slice($answer_with_hash, null, 20);
        $answer = array_slice($answer_with_hash, 20, null);
        $server_DH_inner_data = deserialize(io::BytesIO($answer));
        assert(($nonce == $server_DH_inner_data['nonce']));
        assert(($server_nonce == $server_DH_inner_data['server_nonce']));
        $dh_prime_str = $server_DH_inner_data['dh_prime'];
        $g = $server_DH_inner_data['g'];
        $g_a_str = $server_DH_inner_data['g_a'];
        $server_time = $server_DH_inner_data['server_time'];
        $this->timedelta = ($server_time - time());
        pyjslib_printnl(sprintf('Server-client time delta = %.1f s', $this->timedelta));
        $dh_prime = new bytes_to_long($dh_prime_str);
        $g_a = new bytes_to_long($g_a_str);
        assert(prime::isprime($dh_prime));
        $retry_id = 0;
        $b_str = random_bytes(256);
        $b = new bytes_to_long($b_str);
        $g_b = pow($g, $b, $dh_prime);
        $g_b_str = new long_to_bytes($g_b);
        $data = py2php_kwargs_function_call('serialize_obj', ['client_DH_inner_data'], ["nonce" => $nonce, "server_nonce" => $server_nonce, "retry_id" => $retry_id, "g_b" => $g_b_str]);
        $data_with_sha = (sha1($data, true) + $data);
        $data_with_sha_padded = ($data_with_sha + random_bytes((-count($data_with_sha) % 16)));
        $encrypted_data = crypt::ige_encrypt($data_with_sha_padded, $tmp_aes_key, $tmp_aes_iv);
        foreach (pyjslib_range(1, $this->AUTH_MAX_RETRY) as $i) {
            $Set_client_DH_params_answer = py2php_kwargs_method_call($this, 'method_call', ['set_client_DH_params'], ["nonce" => $nonce, "server_nonce" => $server_nonce, "encrypted_data" => $encrypted_data]);
            $auth_key = pow($g_a, $b, $dh_prime);
            $auth_key_str = new long_to_bytes($auth_key);
            $auth_key_sha = sha1($auth_key_str, true);
            $auth_key_aux_hash = array_slice($auth_key_sha, null, 8);
            $new_nonce_hash1 = array_slice(sha1($new_nonce . '' . $auth_key_aux_hash, true), -16, null);
            $new_nonce_hash2 = array_slice(sha1($new_nonce . '' . $auth_key_aux_hash, true), -16, null);
            $new_nonce_hash3 = array_slice(sha1($new_nonce . '' . $auth_key_aux_hash, true), -16, null);
            assert(($Set_client_DH_params_answer['nonce'] == $nonce));
            assert(($Set_client_DH_params_answer['server_nonce'] == $server_nonce));
            if (($Set_client_DH_params_answer->name == 'dh_gen_ok')) {
                assert(($Set_client_DH_params_answer['new_nonce_hash1'] == $new_nonce_hash1));
                pyjslib_printnl('Diffie Hellman key exchange processed successfully');
                $this->server_salt = new strxor(array_slice($new_nonce, 0, 8 - 0), array_slice($server_nonce, 0, 8 - 0));
                $this->auth_key = $auth_key_str;
                $this->auth_key_id = array_slice($auth_key_sha, -8, null);
                pyjslib_printnl('Auth key generated');
                return 'Auth Ok';
            } else if (($Set_client_DH_params_answer->name == 'dh_gen_retry')) {
                assert(($Set_client_DH_params_answer['new_nonce_hash2'] == $new_nonce_hash2));
                pyjslib_printnl('Retry Auth');
            } else if (($Set_client_DH_params_answer->name == 'dh_gen_fail')) {
                assert(($Set_client_DH_params_answer['new_nonce_hash3'] == $new_nonce_hash3));
                pyjslib_printnl('Auth Failed');
                throw new $Exception('Auth Failed');
            } else {
                throw new $Exception('Response Error');
            }
        }
    }
    function aes_calculate($msg_key, $direction = 'to server') {
        $x = ($direction == 'to server') ? 0 : 8;
        $sha1_a = sha1(($msg_key + array_slice($this->auth_key, $x, ($x + 32) - $x)), true);
        $sha1_b = sha1(((array_slice($this->auth_key, ($x + 32), ($x + 48) - ($x + 32)) + $msg_key) + array_slice($this->auth_key, (48 + $x), (64 + $x) - (48 + $x))), true);
        $sha1_c = sha1((array_slice($this->auth_key, ($x + 64), ($x + 96) - ($x + 64)) + $msg_key))->digest();
        $sha1_d = sha1(($msg_key + array_slice($this->auth_key, ($x + 96), ($x + 128) - ($x + 96))))->digest();
        $aes_key = ((array_slice($sha1_a, 0, 8 - 0) + array_slice($sha1_b, 8, 20 - 8)) + array_slice($sha1_c, 4, 16 - 4));
        $aes_iv = (((array_slice($sha1_a, 8, 20 - 8) + array_slice($sha1_b, 0, 8 - 0)) + array_slice($sha1_c, 16, 20 - 16)) + array_slice($sha1_d, 0, 8 - 0));
        return [$aes_key, $aes_iv];
    }
}
