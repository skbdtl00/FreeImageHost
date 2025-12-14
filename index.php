<?php
// Simple PHP image uploader with size and rate limits.

// Configuration
$maxFileSize = 7 * 1024 * 1024; // 7MB
$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];
$uploadDir = __DIR__ . '/uploads';
$rateLimitDir = __DIR__ . '/ratelimit';
$rateLimitWindow = 60; // seconds
$rateLimitCount = 10; // uploads per window per IP

$errors = [];
$successUrl = null;

/** Ensure a directory exists. */
function ensureDirectory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

/** Create a filesystem-safe file name from an IP address. */
function rateLimitFileName(string $ip): string
{
    return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $ip) ?: 'unknown';
}

/**
 * Return timestamps of uploads within the current window.
 *
 * @return int[]
 */
function loadRecentAttempts(string $filePath, int $window): array
{
    $now = time();
    if (!file_exists($filePath)) {
        return [];
    }

    $lines = array_map('trim', file($filePath));
    $recent = [];
    foreach ($lines as $line) {
        if ($line === '' || !ctype_digit($line)) {
            continue;
        }
        $timestamp = (int) $line;
        if (($now - $timestamp) < $window) {
            $recent[] = $timestamp;
        }
    }

    return $recent;
}

function writeAttempts(string $filePath, array $timestamps): void
{
    $data = implode("\n", $timestamps);
    if ($data !== '') {
        $data .= "\n";
    }
    file_put_contents($filePath, $data, LOCK_EX);
}

function buildRawUrl(string $fileName): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $path = ($basePath === '' || $basePath === '.') ? '' : $basePath;

    return sprintf('%s://%s%s/uploads/%s', $scheme, $host, $path, $fileName);
}

ensureDirectory($uploadDir);
ensureDirectory($rateLimitDir);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateFile = $rateLimitDir . '/' . rateLimitFileName($ip) . '.log';

    $recentAttempts = loadRecentAttempts($rateFile, $rateLimitWindow);
    if (count($recentAttempts) >= $rateLimitCount) {
        $errors[] = 'Rate limit reached: please wait a moment before uploading more images.';
    }

    if (empty($errors)) {
        if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            $errors[] = 'No file uploaded. Please choose an image to upload.';
        } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload error occurred. Please try again.';
        } elseif ($_FILES['image']['size'] > $maxFileSize) {
            $errors[] = 'File exceeds the 7MB limit.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['image']['tmp_name']);
            if (!isset($allowedTypes[$mime])) {
                $errors[] = 'Unsupported file type. Please upload a JPG, PNG, GIF, or WEBP image.';
            }
        }
    }

    if (empty($errors)) {
        $extension = $allowedTypes[$mime];
        $fileName = sprintf('%s.%s', bin2hex(random_bytes(8)), $extension);
        $destination = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
            $errors[] = 'Failed to store the uploaded image.';
        } else {
            $recentAttempts[] = time();
            writeAttempts($rateFile, $recentAttempts);
            $successUrl = buildRawUrl($fileName);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Free Image Host</title>
    <style>
        :root {
            font-family: Arial, sans-serif;
            background: #f6f8fb;
            color: #1f2937;
        }
        body {
            margin: 0;
            display: flex;
            justify-content: center;
            padding: 40px 16px;
        }
        .card {
            max-width: 520px;
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            width: 100%;
        }
        h1 {
            margin-top: 0;
        }
        .notice {
            background: #e0f2fe;
            color: #0f172a;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            border: 1px solid #bae6fd;
        }
        .errors {
            background: #fef2f2;
            color: #7f1d1d;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            border: 1px solid #fecdd3;
        }
        .success {
            background: #ecfdf3;
            color: #14532d;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            border: 1px solid #bbf7d0;
            word-break: break-all;
        }
        form {
            display: grid;
            gap: 12px;
        }
        input[type="file"] {
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #f9fafb;
        }
        button {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 120ms ease, box-shadow 120ms ease;
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.25);
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 30px rgba(99, 102, 241, 0.3);
        }
        button:active {
            transform: translateY(0);
            box-shadow: 0 8px 22px rgba(99, 102, 241, 0.2);
        }
        .info {
            font-size: 0.95rem;
            color: #4b5563;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Free Image Host</h1>
        <p class="info">Upload images up to 7MB. Each IP can upload up to 10 images per minute. Supported formats: JPG, PNG, GIF, WEBP.</p>
        <div class="notice">Images are stored locally. Share the raw URL after upload to embed anywhere.</div>

        <?php if (!empty($errors)): ?>
            <div class="errors">
                <strong>Upload failed:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($successUrl): ?>
            <div class="success">
                <strong>Success!</strong><br>
                Raw image URL: <a href="<?= htmlspecialchars($successUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($successUrl, ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxFileSize ?>">
            <label>
                <strong>Select image:</strong><br>
                <input type="file" name="image" accept="image/*" required>
            </label>
            <button type="submit">Upload</button>
        </form>
    </main>
</body>
</html>
