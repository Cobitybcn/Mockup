<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Video/VideoHttp.php';

function run_video_media_range_regression_tests(): void
{
    TestHarness::group('Video media: bounded byte ranges');

    $size = 40 * 1024 * 1024;
    $chunk = 8 * 1024 * 1024;

    TestHarness::assertSame(
        ['status' => 206, 'start' => 0, 'end' => $chunk - 1],
        VideoHttp::mediaByteRange($size, 'bytes=0-'),
        'an open browser range is capped below the Cloud Run response limit'
    );
    TestHarness::assertSame(
        ['status' => 206, 'start' => $chunk, 'end' => (2 * $chunk) - 1],
        VideoHttp::mediaByteRange($size, 'bytes=' . $chunk . '-'),
        'subsequent playback ranges continue from the requested offset'
    );
    TestHarness::assertSame(
        ['status' => 206, 'start' => 100, 'end' => 999],
        VideoHttp::mediaByteRange($size, 'bytes=100-999'),
        'small explicit ranges preserve their requested boundary'
    );
    TestHarness::assertSame(
        ['status' => 200, 'start' => 0, 'end' => $size - 1],
        VideoHttp::mediaByteRange($size, ''),
        'non-range requests keep full-file download semantics'
    );
    TestHarness::assertSame(
        null,
        VideoHttp::mediaByteRange($size, 'bytes=' . $size . '-'),
        'ranges beyond the end of the object are rejected'
    );
}
