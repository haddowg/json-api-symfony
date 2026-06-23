<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Resource\Field\BelongsTo;

/**
 * The in-memory `actionWidgets` resource (the mount type for the custom-action
 * conformance suite), served by the writable {@see WidgetFactory} provider/persister.
 *
 * It adds a self-referential to-one `related` relation (read off the in-memory
 * {@see Widget::$related} property) so `?include=related` renders another
 * `actionWidgets` resource as an INCLUDED member — the witness that the asLink
 * resource-link contributor runs for every rendered resource of the type, not only the
 * primary one (bundle ADR 0091). The Doctrine kernel keeps the bare base (no relation),
 * since the included-member render is provider-agnostic and proven on the in-memory side.
 */
final class WidgetResource extends BaseWidgetResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsTo::make('related', 'actionWidgets'),
        ];
    }
}
