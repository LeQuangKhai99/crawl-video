<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class DownloadVideoCommand extends Command
{
    // Lệnh dùng để chạy: php artisan video:download "link_web"
    protected $signature = 'video:download {url}';
    protected $description = 'Tool tải video từ JW Player (m3u8)';

    public function handle()
    {
        $url = $this->argument('url');
        $this->info("Đang xử lý lấy link từ: {$url}");

        // Cào HTML của trang gốc JW Player
        $html = Http::get($url)->body();
        
        // 1. Dùng Regex để tìm biến chứa dữ liệu video (mảng JSON)
        preg_match('/const playlistData = (\[.*?\]);/s', $html, $matches);

        $m3u8_link = null;
        $fileName = 'video_tai_ve_' . time();

        if (!empty($matches[1])) {
            // 2. Chuyển JSON chuỗi thành mảng PHP array
            $playlist = json_decode($matches[1], true);
            
            // 3. Lấy link đầu tiên trong danh sách
            $m3u8_raw_link = $playlist[0]['file'] ?? null;
            
            if ($m3u8_raw_link) {
                // Link lấy ra thường bị cụt (chỉ có /streaming-media/...), nên ta ghép nối domain vào
                $parsed = parse_url($url);
                $domain = $parsed['scheme'] . '://' . $parsed['host'];
                $m3u8_link = str_starts_with($m3u8_raw_link, 'http') ? $m3u8_raw_link : rtrim($domain, '/') . '/' . ltrim($m3u8_raw_link, '/');
                
                // Trích xuất luôn tên video để lưu file cho mượt!
                $rawTitle = $playlist[0]['title'] ?? 'video';
                $cleanTitle = preg_replace('/[^A-Za-z0-9\-\s_]/', '', $rawTitle);
                $fileName = trim($cleanTitle) . '_' . time();
            }
        }

        if (!$m3u8_link) {
            $this->error("Không tìm thấy link m3u8 trong biến playlistData!");
            return;
        }

        $this->info("Đã tóm được tên video: {$fileName}");
        $this->info("Đã tóm được link gốc: {$m3u8_link}");
        $this->info("Bắt đầu tải và convert sang MP4...");

        // Chắc chắn thư mục app/videos tồn tại
        if (!is_dir(storage_path('app/videos'))) {
            mkdir(storage_path('app/videos'), 0777, true);
        }

        // Nơi lưu trữ video tải về trong Laravel (thư mục storage/app/videos)
        $outputPath = storage_path("app/videos/{$fileName}.mp4");

        // Giả lập Trình duyệt thực để vượt qua lỗi 403 Forbidden (do máy chủ chặn tải lậu)
        // Ta sử dụng domain vừa parse ở trên làm Referer
        $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36";
        $referer = rtrim($domain ?? 'https://www.o9o.net', '/') . '/'; 

        // BƯỚC QUAN TRỌNG: Gọi YT-DLP với các Header giả mạo thiết bị
        // Đồng thời dùng `forever()` để PHP không bị Timeout (mặc định 60 giây) khi đang tải file nặng
        $command = "yt-dlp --user-agent \"{$userAgent}\" --add-header \"Referer: {$referer}\" --add-header \"Origin: {$referer}\" -f bestvideo+bestaudio/best --merge-output-format mp4 \"{$m3u8_link}\" -o \"{$outputPath}\"";
        
        $this->info("Đang chạy lệnh tải (Có thể mất vài phút tuỳ mạng)...");
        $process = Process::forever()->run($command);

        if ($process->successful()) {
            $this->info("Thành công! Video được lưu tại: {$outputPath}");
        } else {
            $this->error("Lỗi khi tải: " . $process->errorOutput());
        }
    }
}
