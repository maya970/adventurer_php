<?php

declare(strict_types=1);

require_once __DIR__ . '/rpg_smtp_client.php';

/**
 * mail：PHP mail()（多数共享主机无法配合 cPanel 邮箱账号）。
 * smtp：使用下方 smtp_* 连接邮件服务器（与 Outlook/手机「手动设置」一致）。
 *
 * @param array<string,mixed> $cfg
 */
function rpg_mail_transport(array $cfg): string
{
    $m = $cfg['mail'] ?? [];
    $t = strtolower(trim((string) ($m['transport'] ?? 'mail')));

    return $t === 'smtp' ? 'smtp' : 'mail';
}

/**
 * @param array<string,mixed> $cfg
 */
function rpg_mail_is_enabled(array $cfg): bool
{
    $m = $cfg['mail'] ?? null;
    if (!is_array($m) || empty($m['enabled'])) {
        return false;
    }
    $from = trim((string) ($m['from_address'] ?? ''));
    if ($from === '') {
        return false;
    }
    if (rpg_mail_transport($cfg) === 'smtp') {
        $h = trim((string) ($m['smtp_host'] ?? ''));
        $u = trim((string) ($m['smtp_user'] ?? ''));
        $p = (string) ($m['smtp_pass'] ?? '');

        return $h !== '' && $u !== '' && $p !== '';
    }

    return true;
}

/**
 * 玩家可见的补充说明：未启用 mail() 时邮件往往发不出去。
 *
 * @param array<string,mixed> $cfg
 */
function rpg_mail_disabled_player_hint(array $cfg): string
{
    if (rpg_mail_is_enabled($cfg)) {
        return '';
    }

    return '（提示：当前站点未启用发信，邮件多半无法送达；绑定仍会保存，请到「账号邮箱」页在主机支持发信后使用「重新发送验证邮件」，或联系服主。）';
}

/**
 * @param array<string,mixed> $cfg
 */
function rpg_mail_from_header(array $cfg): string
{
    $m = $cfg['mail'] ?? [];
    $addr = trim((string) ($m['from_address'] ?? ''));
    $name = trim((string) ($m['from_name'] ?? 'RPG'));
    if ($addr === '') {
        return '';
    }
    if ($name === '') {
        return $addr;
    }
    $enc = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n")
        : $name;

    return sprintf('%s <%s>', $enc, $addr);
}

/**
 * @param array<string,mixed> $cfg
 */
function rpg_public_base_url(array $cfg): string
{
    $u = trim((string) ($cfg['public_base_url'] ?? ''));
    if ($u !== '') {
        return rtrim($u, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    return $host !== '' ? $scheme . '://' . $host : '';
}

/**
 * @param array<string,mixed> $cfg
 */
function rpg_send_mail(array $cfg, string $to, string $subject, string $plainBody): bool
{
    $to = trim($to);
    if ($to === '' || !rpg_mail_is_enabled($cfg)) {
        return false;
    }
    $m = $cfg['mail'] ?? [];
    $fromAddr = trim((string) ($m['from_address'] ?? ''));
    $fromName = trim((string) ($m['from_name'] ?? ''));
    if ($fromAddr === '') {
        return false;
    }

    if (rpg_mail_transport($cfg) === 'smtp') {
        return rpg_smtp_send_plain($m, $fromAddr, $fromName, $to, $subject, $plainBody);
    }

    $from = rpg_mail_from_header($cfg);
    if ($from === '') {
        return false;
    }
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . $from,
    ];
    $ok = @mail($to, $subject, $plainBody, implode("\r\n", $headers));

    return $ok === true;
}

/**
 * @param array<string,mixed> $cfg
 */
function rpg_send_registration_verify_mail(array $cfg, string $to, string $username, string $token): void
{
    $base = rpg_public_base_url($cfg);
    $path = 'verify-email.html?token=' . rawurlencode($token);
    $link = $base !== '' ? $base . '/' . $path : $path;
    $body = "【冒险者地牢】\n\n账号：{$username}\n请点击以下链接完成邮箱验证（48 小时内有效）：\n{$link}\n\n若未注册请忽略本邮件。";
    rpg_send_mail($cfg, $to, '冒险者地牢 — 邮箱验证', $body);
}

/**
 * @param array<string,mixed> $cfg
 */
function rpg_send_auction_sold_mail(array $cfg, string $to, string $itemLabel, int $priceGold, int $sellerNetGold): void
{
    $body = "【冒险者地牢】\n\n您在拍卖行上架的物品已成交。\n物品：{$itemLabel}\n成交价（买家支付）：{$priceGold} 金币\n您实收：{$sellerNetGold} 金币（已扣平台手续费）\n";
    rpg_send_mail($cfg, $to, '冒险者地牢 — 拍卖成交通知', $body);
}
