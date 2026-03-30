<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class DownloadVideoCommand extends Command
{
    // Lệnh dùng để chạy: php artisan video:download "links.txt" hoặc "link_url"
    protected $signature = 'video:download {input=links.txt : Đường dẫn tới file txt chứa link hoặc 1 link trực tiếp}';
    protected $description = 'Tool tải video từ JW Player (hỗ trợ cào hàng loạt từ file txt)';

    public function handle()
    {
        $input = $this->argument('input');

        // Nhận diện xem người dùng truyền vào 1 đường link hay 1 tên file txt
        if (filter_var($input, FILTER_VALIDATE_URL)) {
            $this->processUrl($input);
            return;
        }

        // Xử lý dạng File danh sách (Mặc định tìm file trong thư mục storage/app/)
        $filePath = storage_path('app/' . ltrim($input, '/'));
        
        if (!file_exists($filePath)) {
            $this->error("🚫 Không tìm thấy file danh sách link: {$filePath}");
            $this->info("👉 HƯỚNG DẪN: Hãy tạo file 'storage/app/{$input}', dán tất cả các link cần tải vào (mỗi link 1 dòng) rôi chạy lại lệnh.");
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            $this->warn("File {$input} hiện không có link nào để tải!");
            return;
        }

        $this->info("🚀 Đã nạp " . count($lines) . " link từ file {$input}. Bắt đầu cày cuốc...\n");

        foreach ($lines as $index => $url) {
            $url = trim($url);
            if (empty($url)) continue;

            $this->info("=================================================");
            $this->info("🔄 ĐANG XỬ LÝ LINK: {$url}");
            
            $success = $this->processUrl($url);

            if ($success) {
                // Tải xong trọn vẹn: 
                // 1. Thêm link này vào file da_tai_xong.txt để lưu trữ lịch sử
                file_put_contents(storage_path('app/da_tai_xong.txt'), $url . PHP_EOL, FILE_APPEND);
                
                // 2. Xóa link này khỏi mảng gốc và cập nhật lại file links.txt (để lỡ có dừng ngang thì mất link đã tải)
                unset($lines[$index]);
                file_put_contents($filePath, implode(PHP_EOL, $lines) . (count($lines) > 0 ? PHP_EOL : ''));
                
                $this->info("✅ Đã ghi nhận tải xong và xóa khỏi danh sách chờ.\n");
            } else {
                $this->error("❌ Có lỗi xảy ra trong quá trình tải playlist của link này.");
                $this->info("Tool sẽ tạm thời bỏ qua (giữ nguyên link trong danh sách) và chạy sang link tiếp theo...\n");
            }
        }
        
        $this->info("🎉 Đã quét hết danh sách trong file!");
    }

    /**
     * Hàm bóc tách giao diện trang và tải toàn bộ video
     */
    private function processUrl($url)
    {
        $html = Http::get($url)->body();
        
        // Dùng Regex tìm cục JS chứa thông tin video
        preg_match('/const playlistData = (\[.*?\]);/s', $html, $matches);

        if (empty($matches[1])) {
            $this->error("   => Không tìm thấy playlistData trong mã nguồn, có thể web đã đổi cấu trúc!");
            return false;
        }

        $playlist = json_decode($matches[1], true);

        if (empty($playlist) || !is_array($playlist)) {
            $this->error("   => Lỗi đọc danh sách video (playlist trống)!");
            return false;
        }

        $totalVideos = count($playlist);
        $this->info("   >> Phát hiện có {$totalVideos} video (bài tập) cần tải trong link này.");

        $parsed = parse_url($url);
        $domain = isset($parsed['scheme'], $parsed['host']) ? $parsed['scheme'] . '://' . $parsed['host'] : 'https://www.o9o.net';
        
        // Trích xuất tham số "lesson" từ URL (ví dụ: lesson=2023-12-001)
        parse_str($parsed['query'] ?? '', $queryParams);
        $lessonName = $queryParams['lesson'] ?? 'unknown_lesson';
        $lessonName = preg_replace('/[^A-Za-z0-9\-\_]/', '', $lessonName); // Xóa ký tự lạ

        $lessonDir = storage_path('app/videos/' . $lessonName);
        if (!is_dir($lessonDir)) {
            mkdir($lessonDir, 0777, true);
        }
        
        $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36";
        $referer = rtrim($domain, '/') . '/'; 

        $allSuccess = true;

        foreach ($playlist as $index => $item) {
            $m3u8_raw_link = $item['file'] ?? null;
            if (!$m3u8_raw_link) {
                continue;
            }

            // Gắn domain nếu link tương đối
            $m3u8_link = str_starts_with($m3u8_raw_link, 'http') ? $m3u8_raw_link : rtrim($domain, '/') . '/' . ltrim($m3u8_raw_link, '/');
            
            // Xử lý tên cho sạch khuẩn
            $rawTitle = $item['title'] ?? 'video_' . ($index + 1);
            $cleanTitle = preg_replace('/[^A-Za-z0-9\-\s_ệôốốồổỗộợờởỡờớừơớờởỡợứừửữựòỏõóọàảãáạèẻẽéẹìỉĩíịỳỷỹýỵđÂÂÊÊÔÔĐĂĂÂÂ]/', '', str_replace(' ', '_', $rawTitle));
            $fileName = trim($cleanTitle, '_') . '_' . time();
            
            $outputPath = $lessonDir . "/{$fileName}.mp4";

            $this->info("   [" . ($index + 1) . "/{$totalVideos}] Kéo file MP4: {$rawTitle}...");
            
            $command = "yt-dlp --user-agent \"{$userAgent}\" --add-header \"Referer: {$referer}\" --add-header \"Origin: {$referer}\" -f bestvideo+bestaudio/best --merge-output-format mp4 \"{$m3u8_link}\" -o \"{$outputPath}\"";
            
            $process = Process::forever()->run($command);

            if ($process->successful()) {
                $this->info("     => XONG! Đã lưu tại: videos/{$lessonName}/{$fileName}.mp4");
            } else {
                $this->error("     => LỖI: " . $process->errorOutput());
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }
}
