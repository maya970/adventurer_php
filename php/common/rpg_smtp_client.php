<?php

declare(strict_types=1);

/**
 * 极简 SMTP 客户端（AUTH LOGIN），用于 cPanel 等企业邮箱。
 * 支持：ssl:// 隐式 TLS（常见 465）、tcp + STARTTLS（常见 587）。
 *
 * @param array<string,mixed> $mail mail 配置块
 */
function rpg_smtp_send_plain(array $mail, string $fromAddr, string $fromName, string $to, string $subject, string $plainBody): bool
{
    $host = trim((string) ($mail['smtp_host'] ?? ''));
    $user = trim((string) ($mail['smtp_user'] ?? ''));
    $pass = (string) ($mail['smtp_pass'] ?? '');
    $port = (int) ($mail['smtp_port'] ?? 465);
    $enc = strtolower(trim((string) ($mail['smtp_encryption'] ?? 'ssl')));

    if ($host === '' || $user === '' || $fromAddr === '') {
        return false;
    }

    if ($enc === 'ssl') {
        $p = $port > 0 ? $port : 465;
        $remote = 'ssl://' . $host . ':' . $p;
    } else {
        $p = $port > 0 ? $port : 587;
        $remote = 'tcp://' . $host . ':' . $p;
    }

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp || !is_resource($fp)) {
        return false;
    }
    stream_set_timeout($fp, 25);

    $read = function () use ($fp): string {
        $buf = '';
        while (!feof($fp)) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                break;
            }
            $buf .= $line;
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        return $buf;
    };

    $expect = function (string $resp, array $codes): bool {
        $code = (int) substr($resp, 0, 3);

        return in_array($code, $codes, true);
    };

    $send = function (string $line) use ($fp): void {
        fwrite($fp, $line . "\r\n");
    };

    $banner = $read();
    if (!$expect($banner, [220])) {
        fclose($fp);

        return false;
    }

    $ehloHost = 'localhost';
    $send('EHLO ' . $ehloHost);
    $ehloResp = $read();
    if (!$expect($ehloResp, [250])) {
        fclose($fp);

        return false;
    }

    if ($enc === 'tls' && $port !== 465) {
        $send('STARTTLS');
        $tlsResp = $read();
        if (!$expect($tlsResp, [220])) {
            fclose($fp);

            return false;
        }
        $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$cryptoOk) {
            fclose($fp);

            return false;
        }
        $send('EHLO ' . $ehloHost);
        $ehlo2 = $read();
        if (!$expect($ehlo2, [250])) {
            fclose($fp);

            return false;
        }
    }

    $send('AUTH LOGIN');
    $auth1 = $read();
    if (!$expect($auth1, [334])) {
        fclose($fp);

        return false;
    }
    $send(base64_encode($user));
    $auth2 = $read();
    if (!$expect($auth2, [334])) {
        fclose($fp);

        return false;
    }
    $send(base64_encode($pass));
    $auth3 = $read();
    if (!$expect($auth3, [235])) {
        fclose($fp);

        return false;
    }

    $send('MAIL FROM:<' . $fromAddr . '>');
    $mf = $read();
    if (!$expect($mf, [250])) {
        fclose($fp);

        return false;
    }

    $send('RCPT TO:<' . trim($to) . '>');
    $rc = $read();
    if (!$expect($rc, [250, 251])) {
        fclose($fp);

        return false;
    }

    $send('DATA');
    $d1 = $read();
    if (!$expect($d1, [354])) {
        fclose($fp);

        return false;
    }

    $subEnc = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
        : $subject;

    $fromHdr = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromAddr . '>'
        : $fromAddr;

    $body = str_replace(["\r\n", "\r"], "\n", $plainBody);
    $body = preg_replace('/^\./m', '..', $body);
    $body = str_replace("\n", "\r\n", $body);

    $headers = [
        'From: ' . $fromHdr,
        'To: <' . trim($to) . '>',
        'Subject: ' . $subEnc,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    fwrite($fp, $data . "\r\n");
    $d2 = $read();
    if (!$expect($d2, [250])) {
        fclose($fp);

        return false;
    }

    $send('QUIT');
    fclose($fp);

    return true;
}
