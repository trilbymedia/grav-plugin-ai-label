# AI Label Plugin for Grav

Stamps a visible label such as **AI generated** or **AI altered** on images that you flag as made or changed with AI. It helps sites that want to tell visitors which images came from AI, for example under the transparency rules in Article 50 of the EU AI Act.

> [!NOTE]
> This plugin is a labelling aid, not legal advice. Whether and how you must label an image depends on where you publish and what the image shows, and the rules are still settling. Check with someone qualified for your situation. The wording, design and placement are all configurable so you can follow whatever you are told.

## Requirements

- Grav 2.0 or newer, PHP 8.3 or newer
- Grav 2.2.8 or newer for the `auto_stamp` setting, which labels Markdown images too
- PHP's GD extension with FreeType, which Grav's image processing already needs

No Composer packages. A copy of DejaVu Sans Bold is bundled for the label text.

## Installation

Install it from the Admin2 plugin catalog, or on the command line:

```bash
bin/gpm install ai-label
```

## Flagging an image

Each image carries its AI status in its metadata file, the `.meta.yaml` that sits next to it:

```yaml
# user/pages/01.blog/my-post/hero.jpg.meta.yaml
ai_status: generated
```

| `ai_status` | Meaning | Label |
|---|---|---|
| `generated` | Made with AI | AI generated |
| `altered` | A real image changed with AI | AI altered |
| `none` or not set | No AI used | none |

Values are not case sensitive. Anything else counts as not flagged.

In **Admin2** the media metadata editor (the Edit metadata button on a page media item, or the Media manager) shows an **AI content** dropdown when this plugin is enabled. Pick **No AI used**, **AI altered** or **AI generated** and save; it is written to the image's `.meta.yaml` as `none`, `altered` or `generated`. Turn the dropdown off with the **AI field in the media editor** setting.

## Labelling every flagged image

Turn on **Label every flagged image** (`auto_stamp: true`) and every image flagged `generated` or `altered` gets the label wherever it is shown: images in page Markdown (`![](hero.jpg)`), images in your templates, and every size of a srcset made with `derivatives()`. No template changes are needed. The label goes in the default position at the default size.

This needs **Grav 2.2.8 or later**, which lets plugins act on every image as it is loaded. On older versions the setting has no effect, and you label images in templates with the `ai_label` filter below.

With `auto_stamp` on, the `ai_label` filter leaves an image that is already labelled as it is, so an image never gets two labels, and the filter's position and size are not used. Images that are not flagged, and images flagged `none`, are served exactly as before.

The label is added when the image is loaded, so it comes before any action on the image in Markdown or Twig. A `resize` shrinks the label along with the image, and a crop such as `cropZoom` can cut it off. `derivatives()` is not affected: each srcset size gets its own label. If your pages resize or crop flagged images, leave `auto_stamp` off and add the filter after the action in your templates. Retina files saved next to an image (`hero@2x.jpg`) are not labelled by `auto_stamp`; use `derivatives()` for responsive images.

## Stamping the label in Twig

The `ai_label` filter stamps the label on a flagged image and returns the image, so you can keep chaining image actions after it:

```twig
{{ page.media['hero.jpg']|ai_label.html('Hero image') }}

{# With a position, after a crop, followed by more actions #}
{{ page.media['hero.jpg'].cropZoom(1920, 480)|ai_label('bottom-left').quality(70).url }}

{# With a position and the largest share of the image, in percent #}
{{ page.media['hero.jpg']|ai_label('top-right', 20).url }}

{# Responsive images: every srcset size gets the label #}
{{ page.media['hero.jpg'].derivatives(400, 1600, 400)|ai_label.sizes('100vw').html() }}
```

Images that are not flagged pass through untouched, so it is safe to add the filter to every image in a template. It only works on raster images (JPEG, PNG, WebP and the like); SVGs and animated GIFs come back unchanged.

Positions are `top-left`, `top-center`, `top-right`, `center-left`, `center`, `center-right`, `bottom-left`, `bottom-center` and `bottom-right`. `bottom right` and `right bottom` work too.

The label is placed with Grav's own `watermark()` image action, so it is placed after any resize or crop, sized against the final image, and cached with it. Changing an image's status, the wording, the language or any design setting gives a new cached image; images that are not flagged are served exactly as before.

### Captions and badges instead of, or as well as, the stamp

```twig
{% set image = page.media['hero.jpg'] %}
<figure>
  {{ image.html() }}
  {% if image|ai_label_text %}
    <figcaption class="ai-note">{{ image|ai_label_text }}</figcaption>
  {% endif %}
</figure>

{# The raw value from the metadata file #}
{{ image.ai_status }}
```

`ai_label_text` gives the label wording for the current language, or an empty string when the image is not flagged.

### Markdown images

The filter only works in templates. To label images in page Markdown, turn on `auto_stamp` (Grav 2.2.8 or later), see above.

## Settings

| Setting | Default | What it does |
|---|---|---|
| `auto_stamp` | `false` | Labels every flagged image, Markdown images and srcset sizes included, without the filter. Needs Grav 2.2.8 or later. |
| `labels.generated` | empty | Wording for AI generated images. Empty uses the translated default. |
| `labels.altered` | empty | Wording for AI altered images. Empty uses the translated default. |
| `position` | `bottom-right` | Where the label goes with `auto_stamp`, or when the filter is given no position. |
| `size` | `30` | The largest share of the image width or height the label may take, in percent. On small images the label shrinks to fit. |
| `font_size` | `24` | The largest text size, in pixels. The label is never scaled up beyond it. |
| `text_color` | `#ffffff` | Text and icon color. |
| `background_color` | `#000000` | Background color. |
| `background_opacity` | `70` | `0` for no background, `100` for a solid one. |
| `padding` | `10` | Space around the text inside the label, in pixels. |
| `margin` | `12` | Space between the label and the edge of the image, in pixels. |
| `radius` | `6` | Corner rounding, in pixels. |
| `icon` | `sparkle` | `sparkle` for the built-in icon, `none`, or an image such as `user://images/ai-icon.png`. |
| `font` | empty | A TrueType font such as `user://fonts/MyFont.ttf`. Empty uses the bundled DejaVu Sans Bold. |
| `admin_field` | `true` | Adds the AI content dropdown to the Admin2 media metadata editor. |

Copy `ai-label.yaml` to `user/config/plugins/ai-label.yaml` to change them, or use the plugin settings in Admin2.

### Wording and languages

The default wording comes from the plugin's translations (`PLUGIN_AI_LABEL.LABEL_GENERATED` and `PLUGIN_AI_LABEL.LABEL_ALTERED`), so a multi-language site gets the label in each page's language. English and German ship with the plugin. To change the wording for one language, put your own text in the settings. To change it per language, put a translation key of your own in the settings and define it in `user/languages/<lang>.yaml`:

```yaml
# user/config/plugins/ai-label.yaml
labels:
  generated: SITE.AI_GENERATED
```

```yaml
# user/languages/fr.yaml
SITE:
  AI_GENERATED: 'Généré par IA'
```

Long wording wraps onto more lines. Right-to-left text such as Hebrew is drawn right to left with the icon on its right. Arabic letters are drawn but not joined, as GD cannot join them. The bundled font covers Latin, Greek, Cyrillic and Hebrew; for other scripts set `font` to a TrueType font that has them.

### Site-wide watermark

If `system.images.watermark.watermark_all` is on, labelled images keep the site watermark as well as the label.

## Development

The label drawing and the guard that keeps `auto_stamp` and the filter from labelling an image twice are tested with PHPUnit, using a Grav checkout for the runner:

```bash
GRAV_ROOT=/path/to/grav php /path/to/grav/vendor/bin/phpunit
```

## License

MIT, see [LICENSE](LICENSE). The bundled DejaVu Sans Bold font has its own license, see [fonts/DejaVu-LICENSE.txt](fonts/DejaVu-LICENSE.txt).
