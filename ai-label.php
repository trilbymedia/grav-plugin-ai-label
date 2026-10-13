<?php

namespace Grav\Plugin;

use Grav\Common\Media\Interfaces\ImageMediaInterface;
use Grav\Common\Plugin;
use Grav\Plugin\AiLabel\LabelRenderer;
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

        $this->enable([
            'onTwigInitialized' => ['onTwigInitialized', 0],
        ]);
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
     * images and anything that is not a raster image come back untouched.
     *
     * @param mixed $medium
     * @param string|null $position e.g. `bottom-right`, `top left`, `center`
     * @param int|string|null $size Largest share of the image, in percent
     * @return mixed
     */
    public function stamp($medium, $position = null, $size = null)
    {
        if (!$medium instanceof ImageMediaInterface || !method_exists($medium, 'watermark')) {
            return $medium;
        }

        $status = $this->status($medium);
        $file = $status ? $this->labelFile($status) : null;
        if (!$file) {
            return $medium;
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

        return $medium;
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
     * The api plugin knows text, textarea and tags fields. The field asks for
     * `select` and lists its options, so it turns into a choice once the api
     * plugin supports one; until then the api plugin shows it as a text field.
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
