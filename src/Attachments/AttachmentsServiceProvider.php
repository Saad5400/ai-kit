<?php

namespace Saad\AiKit\Attachments;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AttachmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExtractionCache::class, function (Application $app) {
            $config = $app['config'];

            return new ExtractionCache(
                $app['cache']->store($config->get('ai-kit.attachments.cache.store')),
                $config->get('ai-kit.attachments.cache.version', 'v1'),
                (int) $config->get('ai-kit.attachments.cache.ttl_days', 14),
            );
        });

        $this->app->singleton(PdfTextLayer::class, function (Application $app) {
            $config = $app['config'];

            return new PdfTextLayer(
                (int) $config->get('ai-kit.attachments.pdf.min_chars_per_page', 80),
                (float) $config->get('ai-kit.attachments.pdf.max_junk_ratio', 0.10),
                (int) $config->get('ai-kit.attachments.pdf.timeout', 60),
                $config->get('ai-kit.attachments.pdf.pdftotext_binary', 'pdftotext'),
                $config->get('ai-kit.attachments.pdf.pdfinfo_binary', 'pdfinfo'),
            );
        });

        $this->app->singleton(LocalTextExtractor::class);

        $this->app->singleton(ExtractionRouter::class);
    }
}
