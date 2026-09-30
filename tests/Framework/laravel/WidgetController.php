<?php

declare(strict_types=1);

namespace FrameworkLaravel;

use FrameworkFixture\Api\WidgetsApiController;
use FrameworkFixture\Model\Widget;
use FrameworkFixture\Model\WidgetInput;

final class WidgetController extends WidgetsApiController
{
    public function getWidget(string $widgetId): Widget
    {
        return new Widget($widgetId, 'Found');
    }

    public function createWidget(WidgetInput $body): Widget
    {
        return new Widget('created', $body->name);
    }

    public function updateWidget(string $widgetId, WidgetInput $body): Widget
    {
        return new Widget($widgetId, $body->name);
    }

    public function deleteWidget(string $widgetId): void
    {
    }
}
