<?php

namespace Tests\Unit;

use App\Admin\Controllers\AiSessionController;
use Tests\TestCase;

class AiSessionControllerFormTest extends TestCase
{
    public function test_session_edit_form_only_exposes_title_field()
    {
        $form = $this->makeSessionForm();
        $this->buildForm($form);

        $columns = $form->fields()->map(function ($field) {
            return $field->column();
        })->all();

        $this->assertSame(['title'], $columns);
        $this->assertSame('标题', $form->field('title')->label());
        $this->assertSame('512', $form->field('title')->getAttribute('maxlength'));
    }

    private function makeSessionForm()
    {
        $controller = app(AiSessionController::class);
        $method = new \ReflectionMethod($controller, 'form');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }

    private function buildForm($form)
    {
        $method = new \ReflectionMethod($form, 'build');
        $method->setAccessible(true);

        $method->invoke($form);
    }
}
