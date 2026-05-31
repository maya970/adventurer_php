<?php
/**
 * 复制本文件为 config.php，并填写真实值。
 *
 * 数据库：
 * - InfinityFree：控制面板 → MySQL。
 * - iFastNet 等 cPanel：MySQL Databases；PHP 与库同机时常用 host「localhost」。
 *
 * admin.password：用于浏览器打开 admin_kills.php 查看击杀审计，务必改为高强度口令。
 *
 * 勿将含真实密码的 config.php 提交到公开仓库。
 */
return [
    /** 前端校验：修改后登录页/主城会清缓存并刷新（与后端一致即可） */
    'app_build' => '2026-05-22-town-labels-1',
    /**
     * 站点对外根 URL（无尾斜杠），用于验证邮件中的链接。
     * 示例：https://mxz.es.ht 或子域完整地址。
     */
    'public_base_url' => 'https://你的站点根域名',
    /**
     * 发信（与 cPanel「Mail Client Manual Settings → Outgoing Server」一致，只用到 SMTP）：
     * - Secure SSL/TLS：Outgoing mail.mxz.es.ht，SMTP Port 465，需要认证。
     * - transport=smtp + smtp_encryption=ssl + smtp_port=465
     * - smtp_user / from_address：填完整邮箱，例如 code@mxz.es.ht
     * - smtp_pass：该邮箱在 cPanel 中的密码（若与数据库密码相同，可在 config.php 里填相同字符串，但仍是两个独立配置项）。
     * 非 SSL 不推荐：SMTP 587 时可设 smtp_encryption=tls。
     */
    'mail' => [
        'enabled' => false,
        'transport' => 'smtp',
        'smtp_host' => 'mail.mxz.es.ht',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
        'smtp_user' => 'code@mxz.es.ht',
        'smtp_pass' => '填写该邮箱在 cPanel 中的密码',
        'from_address' => 'code@mxz.es.ht',
        'from_name' => '冒险者地牢',
    ],
    'db' => [
        /** InfinityFree 示例 */
        'host' => 'sqlXXX.infinityfree.com',
        'name' => 'if0_XXXXXX_rpg',
        'user' => 'if0_XXXXXX',
        'pass' => '你的数据库密码',
        /** iFastNet / cPanel 同机示例（把下面四行改成你的库名与用户；host 常见为 localhost） */
        // 'host' => 'localhost',
        // 'name' => 'cpanel前缀_库名',
        // 'user' => 'cpanel前缀_库用户',
        // 'pass' => '你的数据库密码',
        'charset' => 'utf8mb4',
    ],
    'admin' => [
        'password' => '请改成足够长的随机口令（20 位以上）',
    ],
];
