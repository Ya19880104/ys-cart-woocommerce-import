<?php
declare(strict_types=1);

namespace YangSheep\YsCartWooImport\YsCart;

defined('ABSPATH') || exit;

use YangSheep\YsCartWooImport\Database\MapRepository;

/**
 * 圖片落地（v0.5.0 H1 修正）。
 *
 * 問題：原本匯入時把來源 WooCommerce 站的附件 URL 原樣寫進 YS CART 的
 * image_url / gallery_urls。搬家完成、來源站關閉後，所有商品圖即 404——對一個
 * 付費搬家工具是嚴重缺陷。
 *
 * 修法：匯入時把每個外部圖片 URL 下載進「目標站」的媒體庫（media_handle_sideload），
 * 改寫成本地 URL。以 URL 的 sha1 當 maps 表 dedup key（entity='media'），重跑與
 * 跨商品共用同圖時都不重複下載。
 *
 * 設計原則：
 * - **盡力而為**：任一張下載/落地失敗，保留原始 URL 並回傳，「不」中斷整筆商品匯入。
 * - **交易外執行**：網路 I/O 不可放在 DB 交易內（會長時間持鎖），呼叫端需在
 *   Transaction::run() 之前先把 URL 解析成本地 URL。
 * - **可關閉**：匯入 options 的 `sideload_images=false` 可停用（純匯出站或刻意保留
 *   外連時）。
 */
final class MediaSideloader
{
    /** maps 表 source_id 為 varchar(191)，URL 可能超長 → 一律以 sha1 當 key。 */
    private const MEDIA_ENTITY = 'media';

    /**
     * 解析單一圖片 URL → 本地 URL。失敗或停用時回傳原始 URL。
     */
    public function resolveUrl(int $jobId, string $fingerprint, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // 已是本站附件就不需處理（重跑 / 已落地）。
        if ($this->isLocalUrl($url)) {
            return $url;
        }

        $key = sha1($url);
        $mapRepo = new MapRepository();
        $existing = $mapRepo->find($fingerprint, self::MEDIA_ENTITY, $key);
        if ($existing) {
            $local = wp_get_attachment_url((int)$existing->target_id);
            if (is_string($local) && $local !== '') {
                return $local;
            }
            // 附件已被刪 → 落到重新下載。
        }

        $attachmentId = $this->sideload($url);
        if ($attachmentId <= 0) {
            return $url; // 盡力而為：保留原連結。
        }

        $local = wp_get_attachment_url($attachmentId);
        $local = is_string($local) && $local !== '' ? $local : $url;

        $mapRepo->upsert($jobId, $fingerprint, self::MEDIA_ENTITY, $key, $attachmentId, 'attachment', [
            'source_url' => $url,
            'local_url' => $local,
        ]);

        return $local;
    }

    /**
     * 解析一組圖片 URL（gallery）→ 本地 URL 陣列，順序保留、空值剔除。
     *
     * @param array<int,mixed> $urls
     * @return array<int,string>
     */
    public function resolveUrls(int $jobId, string $fingerprint, array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            $resolved = $this->resolveUrl($jobId, $fingerprint, (string)$url);
            if ($resolved !== '') {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    private function isLocalUrl(string $url): bool
    {
        $home = function_exists('home_url') ? (string)home_url() : '';
        if ($home === '') {
            return false;
        }
        $homeHost = (string)wp_parse_url($home, PHP_URL_HOST);
        $urlHost = (string)wp_parse_url($url, PHP_URL_HOST);

        return $homeHost !== '' && strcasecmp($homeHost, $urlHost) === 0;
    }

    /**
     * 下載並落地到媒體庫。回傳 attachment ID，失敗回 0（不拋例外）。
     */
    private function sideload(string $url): int
    {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if (!function_exists('wp_read_image_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $name = basename((string)wp_parse_url($url, PHP_URL_PATH));
        if ($name === '' || strpos($name, '.') === false) {
            $name = 'ys-wc-import-' . substr(sha1($url), 0, 12) . '.jpg';
        }

        $fileArray = [
            'name' => sanitize_file_name($name),
            'tmp_name' => $tmp,
        ];

        $attachmentId = media_handle_sideload($fileArray, 0);

        if (is_wp_error($attachmentId)) {
            if (is_string($tmp) && file_exists($tmp)) {
                @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
            return 0;
        }

        // 成功時 media_handle_sideload 已清掉 $tmp。
        return (int)$attachmentId;
    }
}
