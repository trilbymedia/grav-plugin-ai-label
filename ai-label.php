<?php

namespace Grav\Plugin;

use Grav\Common\Media\Interfaces\ImageMediaInterface;
use Grav\Common\Plugin;
use Grav\Plugin\AiLabel\LabelRenderer;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Twig\TwigFilter;

require_once __DIR__ . '/classes/LabelRenderer.php';

/**
 * Labels images made or changed with AI.
 *
 * An image is flagged with `ai_status: generated` or `ai_status: altered` in
 * its `.meta.yaml`. The `ai_label` Twig filter stamps the label onto a flagged
 * image through Grav's own `watermark()` action, so the label is placed after
 * any resize or crop, reaches srcset derivatives, and is cached with the image.
 *
 * With `auto_stamp` on, the same label is queued on every flagged image from
 * core's `onImageMediumReset` event (Grav 2.2.8 and later), which covers
 * Markdown images too. Older versions never fire the event, so the setting
 * does nothing there.
 */
class AiLabelPlugin extends Plugin
{
    /** The `.meta.yaml` key that holds an image's AI status. */
    public const META_KEY = 'ai_status';

    /** Statuses that get a label. Anything else (`none`, empty) gets nothing. */
    public const STATUSES = ['generated', 'altered'];

    /** Bump when LabelRenderer draws differently, so cached labels are redrawn. */
    private const RENDER_VERSION = 1;

    /** Mirrors the api plugin's built-in metadata fields, used when none are configured. */
    private const API_DEFAULT_META_FIELDS = [
        ['key' => 'alt', 'label' => 'Alt Text', 'type' => 'text'],
        ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
        ['key' => 'caption', 'label' => 'Caption', 'type' => 'textarea'],
        ['key' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ['key' => 'tags', 'label' => 'Tags', 'type' => 'tags'],
    ];

    /** @var array<string, string> Label file stream by status, for this request */
    private array $files = [];

    /**
     * Images auto_stamp has labelled since they were last reset. The filter
     * leaves these alone, so an image is never labelled twice. Only set while
     * auto_stamp is on.
     *
     * @var \WeakMap<object, true>|null
     */
    private ?\WeakMap $autoStamped = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->config->get('plugins.ai-label.admin_field', true)) {
            $this->addMetadataField();
        }

        $events = ['onTwigInitialized' => ['onTwigInitialized', 0]];
        if ($this->config->get('plugins.ai-label.auto_stamp', false)) {
            $events['onImageMediumReset'] = ['onImageMediumReset', 0];
        }

        $this->enable($events);
    }

    /**
     * Core fires this on an image when it is loaded and again after each
     * render, with the image back to its original (Grav 2.2.8 and later).
     * Queues the label on a flagged image, so Markdown images and srcset
     * derivatives get it without the filter.
     */
    public function onImageMediumReset(Event $event): void
    {
        $medium = $event['medium'];
        if (!is_object($medium)) {
            return;
        }

        // The reset dropped whatever label was queued before it.
        $this->autoStamped ??= new \WeakMap();
        unset($this->autoStamped[$medium]);

        if ($this->queueLabel($medium)) {
            $this->autoStamped[$medium] = true;
        }
    }

    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig']->twig();
        $twig->addFilter(new TwigFilter('ai_label', [$this, 'stamp']));
        $twig->addFilter(new TwigFilter('ai_label_text', [$this, 'text']));
    }

    /**
     * `{{ image|ai_label('bottom-right', 25) }}`: queues the label on a flagged
     * image and returns the image, so more actions can follow. Unflagged
     * images, anything that is not a raster image, and images auto_stamp has
     * already labelled come back untouched.
     *
     * @param mixed $medium
     * @param string|null $position e.g. `bottom-right`, `top left`, `center`
     * @param int|string|null $size Largest share of the image, in percent
     * @return mixed
     */
    public function stamp($medium, $position = null, $size = null)
    {
        // Already labelled by auto_stamp: a second label would sit on top of it.
        if ($this->autoStamped && is_object($medium) && isset($this->autoStamped[$medium])) {
            return $medium;
        }

        $this->queueLabel($medium, $position, $size);

        return $medium;
    }

    /**
     * Queues the label on a flagged raster image. Returns whether it did.
     *
     * @param mixed $medium
     * @param string|null $position
     * @param int|string|null $size
     */
    private function queueLabel($medium, $position = null, $size = null): bool
    {
        if (!$medium instanceof ImageMediaInterface || !method_exists($medium, 'watermark')) {
            return false;
        }

        $status = $this->status($medium);
        $file = $status ? $this->labelFile($status) : null;
        if (!$file) {
            return false;
        }

        $config = $this->config;
        $position = static::normalizePosition((string) ($position ?? $config->get('plugins.ai-label.position', 'bottom-right')));
        $size = (int) ($size ?? $config->get('plugins.ai-label.size', 30));
        $size = max(1, min(100, $size ?: 30));

        // Core skips the site-wide watermark on an image that has one of its
        // own, which the label is. Queue the site watermark first to keep it.
        if ($config->get('system.images.watermark.watermark_all', false)) {
            $medium->watermark();
        }

        $medium->watermark($file, $position, $size);

        return true;
    }

    /**
     * `{{ image|ai_label_text }}`: the label wording for a flagged medium in
     * the current language, or an empty string.
     *
     * @param mixed $medium
     */
    public function text($medium): string
    {
        $status = is_object($medium) && method_exists($medium, 'get') ? $this->status($medium) : null;

        return $status ? $this->labelText($status) : '';
    }

    /**
     * The medium's AI status when it is one that gets a label.
     *
     * @param object $medium
     */
    public function status($medium): ?string
    {
        $value = $medium->get(static::META_KEY);
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($value, static::STATUSES, true) ? $value : null;
    }

    public function labelText(string $status): string
    {
        $text = trim((string) $this->config->get("plugins.ai-label.labels.{$status}", ''));

        // A custom wording may itself be a translation key, so a multi-language
        // site can keep one wording per language in user/languages.
        return (string) $this->grav['language']->translate($text !== '' ? $text : 'PLUGIN_AI_LABEL.LABEL_' . strtoupper($status));
    }

    /**
     * The label PNG for a status, drawn on first use. The file name is a hash
     * of everything that changes how the label looks, so new wording or
     * settings give a new file, and with it a new cached image.
     */
    public function labelFile(string $status): ?string
    {
        if (isset($this->files[$status])) {
            return $this->files[$status];
        }

        $config = (array) $this->config->get('plugins.ai-label', []);
        $font = $this->resolvePath((string) ($config['font'] ?? ''));
        $icon = (string) ($config['icon'] ?? 'sparkle');
        if (!in_array($icon, ['', 'none', 'sparkle'], true)) {
            $icon = $this->resolvePath($icon);
        }

        $options = ['text' => $this->labelText($status), 'font' => $font, 'icon' => $icon];
        foreach (array_keys(LabelRenderer::DEFAULTS) as $key) {
            if (!isset($options[$key]) && isset($config[$key])) {
                $options[$key] = $config[$key];
            }
        }

        $hash = sha1(json_encode([
            static::RENDER_VERSION,
            $options,
            is_file($font) ? filemtime($font) : 0,
            is_file($icon) ? filemtime($icon) : 0,
        ]));
        $name = 'images/ai-label/' . substr($hash, 0, 20) . '.png';

        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $cacheDir = $locator->findResource('cache://', true) ?: $locator->findResource('cache://', true, true);
        $path = $cacheDir . '/' . $name;

        if (!is_file($path) && !LabelRenderer::renderToFile($options, $path)) {
            return null;
        }

        return $this->files[$status] = 'cache://' . $name;
    }

    /**
     * Turns `bottom right`, `right-bottom`, `bottom_right` or `center` into
     * the `vertical-horizontal` form core's watermark() takes.
     */
    public static function normalizePosition(string $position): string
    {
        $words = preg_split('/[^a-z]+/', strtolower($position), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $vertical = array_values(array_intersect($words, ['top', 'bottom']))[0] ?? 'center';
        $horizontal = array_values(array_intersect($words, ['left', 'right']))[0] ?? 'center';

        if (!$words) {
            [$vertical, $horizontal] = ['bottom', 'right'];
        }

        return $vertical . '-' . $horizontal;
    }

    /**
     * Adds the AI status to the media metadata editor in Admin2 by adding a
     * field to the api plugin's `media_metadata.fields`, for this request only.
     *
     * It is a `select`, which Admin2 shows as a dropdown of the three statuses.
     */
    private function addMetadataField(): void
    {
        if (!$this->config->get('plugins.api.enabled')) {
            return;
        }

        $fields = $this->config->get('plugins.api.media_metadata.fields');
        if (!is_array($fields) || $fields === []) {
            $fields = static::API_DEFAULT_META_FIELDS;
        }

        foreach ($fields as $field) {
            if (is_array($field) && ($field['key'] ?? null) === static::META_KEY) {
                return;
            }
        }

        $language = $this->grav['language'];
        $fields[] = [
            'key' => static::META_KEY,
            'label' => $language->translate('PLUGIN_AI_LABEL.META_FIELD'),
            'type' => 'select',
            'options' => [
                'none' => $language->translate('PLUGIN_AI_LABEL.STATUS_NONE'),
                'altered' => $language->translate('PLUGIN_AI_LABEL.STATUS_ALTERED'),
                'generated' => $language->translate('PLUGIN_AI_LABEL.STATUS_GENERATED'),
            ],
        ];

        $this->config->set('plugins.api.media_metadata.fields', $fields);
    }

    /**
     * A stream (`user://fonts/label.ttf`) or a path from the Grav root to an
     * absolute file path, or '' when there is no such file.
     */
    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        if ($locator->isStream($path)) {
            return (string) ($locator->findResource($path, true) ?: '');
        }

        $file = GRAV_ROOT . '/' . ltrim($path, '/');

        return is_file($file) ? $file : '';
    }
}
