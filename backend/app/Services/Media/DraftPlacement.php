<?php

namespace McpMate\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\SiteContext\PostStructure;

/**
 * Attaches an uploaded file to a draft: as its parent post, as its featured
 * image, or as an image block appended to its content.
 *
 * Only posts that are not public yet are changed (`draft`, `pending`),
 * and their status is never touched, so nothing the AI adds
 * goes live (security rule 2). The post type must be one PostStructure
 * allows. Content is only appended to block or classic content: a post laid
 * out with a page builder keeps its layout in builder data, so an appended
 * block would not show, and the request is refused instead.
 *
 * The caller checks `edit_post` on the post first.
 */
final class DraftPlacement
{
    /** Statuses whose content may be changed. */
    public const EDITABLE_STATUSES = ['draft', 'pending'];

    /**
     * Everything that can be checked before the file is stored.
     *
     * @throws MediaException
     */
    public static function check(\WP_Post $post, bool $featured, bool $insert): void
    {
        if (!in_array($post->post_type, PostStructure::allowedPostTypes(), true)) {
            throw new MediaException(
                /* translators: %s: post type name. */
                sprintf(esc_html__('Media cannot be added to "%s" posts.', 'mcpmate'), esc_html($post->post_type)),
                'mcpmate_post_type_not_allowed',
                400,
                /* translators: %s: comma-separated post type names. */
                sprintf(esc_html__('Allowed post types: %s.', 'mcpmate'), esc_html(implode(', ', PostStructure::allowedPostTypes())))
            );
        }

        if (!in_array($post->post_status, self::EDITABLE_STATUSES, true)) {
            throw new MediaException(
                esc_html__('Only drafts and posts pending review can be changed.', 'mcpmate'),
                'mcpmate_post_not_draft',
                400,
                esc_html__('Upload without post_id, and ask the user to add the image to the published post in wp-admin.', 'mcpmate')
            );
        }

        if ($featured && !post_type_supports($post->post_type, 'thumbnail')) {
            throw new MediaException(
                /* translators: %s: post type name. */
                sprintf(esc_html__('"%s" posts have no featured image.', 'mcpmate'), esc_html($post->post_type)),
                'mcpmate_featured_unsupported',
                400,
                esc_html__('Leave out featured, or use insert to add the image to the content.', 'mcpmate')
            );
        }

        if ($insert) {
            $builder = PostStructure::describe($post)['builder'];

            if (is_array($builder)) {
                throw new MediaException(
                    /* translators: %s: page builder name, e.g. Elementor. */
                    sprintf(esc_html__('This post is laid out with %s, so an image added to its content would not show.', 'mcpmate'), esc_html((string) $builder['name'])),
                    'mcpmate_post_uses_builder',
                    400,
                    esc_html__('Upload without insert (or set it as the featured image), and ask the user to place it in the page builder.', 'mcpmate')
                );
            }
        }
    }

    /**
     * Checks that need the stored file's type.
     *
     * @throws MediaException
     */
    public static function checkType(string $mime, bool $featured, bool $insert): void
    {
        if (($featured || $insert) && !MediaType::isImage($mime)) {
            throw new MediaException(
                esc_html__('Only images can be set as featured image or inserted into content.', 'mcpmate'),
                'mcpmate_media_not_image',
                400,
                esc_html__('Upload the PDF without featured or insert; it is attached to the post.', 'mcpmate')
            );
        }

        // Core cannot render an SVG thumbnail, so set_post_thumbnail() would refuse it.
        if ($featured && $mime === MediaType::SVG) {
            throw new MediaException(
                esc_html__('An SVG file cannot be a featured image.', 'mcpmate'),
                'mcpmate_featured_unsupported',
                400,
                esc_html__('Use a PNG or JPEG for the featured image, or use insert for the SVG.', 'mcpmate')
            );
        }
    }

    /**
     * Set the featured image and/or append an image block. The post's status
     * stays as it is.
     *
     * @return array{id: int, status: string, featured: bool, inserted: bool, edit_url: string, error: string}
     */
    public static function apply(\WP_Post $post, int $attachmentId, bool $featured, bool $insert, string $alt, string $caption): array
    {
        $result = [
            'id'       => $post->ID,
            'status'   => $post->post_status,
            'featured' => false,
            'inserted' => false,
            'edit_url' => (string) get_edit_post_link($post->ID, 'raw'),
            'error'    => '',
        ];

        if ($featured) {
            $result['featured'] = (bool) set_post_thumbnail($post, $attachmentId);
        }

        if ($insert) {
            $content = rtrim($post->post_content);
            $content = ($content !== '' ? $content . "\n\n" : '') . self::imageBlock($attachmentId, $alt, $caption);
            $updated = wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => $content]), true);

            if (is_wp_error($updated)) {
                $result['error'] = wp_strip_all_tags($updated->get_error_message());
            } else {
                $result['inserted'] = true;
            }
        }

        if ($featured && !$result['featured'] && $result['error'] === '') {
            $result['error'] = __('WordPress did not accept the image as featured image.', 'mcpmate');
        }

        return $result;
    }

    /**
     * A core/image block for the attachment: the `large` size when there is
     * one, alt text and an optional caption.
     */
    public static function imageBlock(int $attachmentId, string $alt, string $caption): string
    {
        $meta = wp_get_attachment_metadata($attachmentId);
        $size = is_array($meta) && isset($meta['sizes']['large']) ? 'large' : 'full';
        $url  = wp_get_attachment_image_url($attachmentId, $size);
        $url  = is_string($url) && $url !== '' ? $url : (string) wp_get_attachment_url($attachmentId);

        $html = '<figure class="wp-block-image size-' . $size . '">'
            . '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="wp-image-' . $attachmentId . '"/>'
            . ($caption !== '' ? '<figcaption class="wp-element-caption">' . wp_kses($caption, MediaUploader::CAPTION_HTML) . '</figcaption>' : '')
            . '</figure>';

        return serialize_block([
            'blockName'    => 'core/image',
            'attrs'        => ['id' => $attachmentId, 'sizeSlug' => $size, 'linkDestination' => 'none'],
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ]);
    }
}
