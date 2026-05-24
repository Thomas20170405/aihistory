<?php

namespace Tests\Feature;

use App\Admin\Controllers\AiSessionController;
use App\Models\AiMessage;
use App\Models\AiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AiSessionControllerPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_timeline_paginates_messages_and_does_not_render_raw_json()
    {
        $session = AiSession::create([
            'source' => 'codex',
            'external_id' => 'session-large',
            'title' => 'Large Session',
            'message_count' => 101,
        ]);

        for ($i = 0; $i < 101; $i++) {
            AiMessage::create([
                'ai_session_id' => $session->id,
                'seq' => $i,
                'role' => 'assistant',
                'type' => 'message',
                'content' => 'visible-content-'.$i,
                'raw' => ['secret_raw_marker' => 'raw-'.$i],
                'metadata' => ['meta_marker' => 'meta-'.$i],
            ]);
        }

        $html = $this->renderTimeline($session, Request::create('/admin/ai-sessions/'.$session->id));

        $this->assertStringContainsString('visible-content-0', $html);
        $this->assertStringContainsString('visible-content-99', $html);
        $this->assertStringNotContainsString('visible-content-100', $html);
        $this->assertStringNotContainsString('secret_raw_marker', $html);
        $this->assertStringContainsString('查看 raw', $html);
    }

    public function test_raw_page_displays_only_the_requested_message_raw_payload()
    {
        $session = AiSession::create([
            'source' => 'cursor',
            'external_id' => 'session-raw',
            'title' => 'Raw Session',
            'message_count' => 2,
        ]);

        $first = AiMessage::create([
            'ai_session_id' => $session->id,
            'seq' => 0,
            'role' => 'user',
            'type' => 'message',
            'content' => 'first',
            'raw' => ['raw_marker' => 'first-raw'],
            'metadata' => ['meta_marker' => 'first-meta'],
        ]);
        AiMessage::create([
            'ai_session_id' => $session->id,
            'seq' => 1,
            'role' => 'assistant',
            'type' => 'message',
            'content' => 'second',
            'raw' => ['raw_marker' => 'second-raw'],
            'metadata' => ['meta_marker' => 'second-meta'],
        ]);

        $html = app(AiSessionController::class)->raw($session->id, $first->id);

        $this->assertStringContainsString('first-raw', $html);
        $this->assertStringContainsString('first-meta', $html);
        $this->assertStringNotContainsString('second-raw', $html);
    }

    public function test_destroy_deletes_session_and_messages_without_form_builder()
    {
        $session = AiSession::create([
            'source' => 'codex',
            'external_id' => 'session-delete',
            'title' => 'Delete Session',
            'message_count' => 2,
        ]);

        AiMessage::create([
            'ai_session_id' => $session->id,
            'seq' => 0,
            'role' => 'user',
            'type' => 'message',
            'content' => 'delete me',
        ]);
        AiMessage::create([
            'ai_session_id' => $session->id,
            'seq' => 1,
            'role' => 'assistant',
            'type' => 'message',
            'content' => 'delete me too',
        ]);

        $response = app(AiSessionController::class)->destroy($session->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'status' => true,
            'data' => [
                'message' => '删除成功',
                'type' => 'success',
                'alert' => true,
            ],
        ], $response->getData(true));
        $this->assertDatabaseMissing('ai_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('ai_messages', ['ai_session_id' => $session->id]);
    }

    private function renderTimeline(AiSession $session, Request $request)
    {
        $controller = app(AiSessionController::class);
        $method = new \ReflectionMethod($controller, 'messageTimeline');
        $method->setAccessible(true);

        return $method->invoke($controller, $session, $request);
    }
}
