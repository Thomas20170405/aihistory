<?php

namespace App\Admin\Controllers;

use App\Models\AiMessage;
use App\Models\AiSession;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Show;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiSessionController extends AdminController
{
    protected $title = 'AI 会话';
    private const MESSAGES_PER_PAGE = 100;

    public function show($id, Content $content)
    {
        $session = AiSession::findOrFail($id);

        return $content
            ->title('AI 会话')
            ->description($session->title ?: $session->external_id)
            ->body($this->detail($session))
            ->body($this->messageTimeline($session, request()));
    }

    public function raw($sessionId, $messageId, Content $content = null)
    {
        $session = AiSession::findOrFail($sessionId);
        $message = AiMessage::where('ai_session_id', $session->id)->findOrFail($messageId);
        $html = $this->rawMessageHtml($session, $message);

        if ($content) {
            return $content
                ->title('消息 Raw')
                ->description(($session->title ?: $session->external_id).' #'.$message->seq)
                ->body($html);
        }

        return $html;
    }

    public function destroy($id)
    {
        $ids = $this->deleteIds($id);

        if (empty($ids)) {
            return JsonResponse::make()
                ->alert()
                ->error('请选择要删除的会话')
                ->send();
        }

        try {
            DB::transaction(function () use ($ids) {
                $sessions = AiSession::query()
                    ->whereKey($ids)
                    ->lockForUpdate()
                    ->get();

                if ($sessions->count() !== count($ids)) {
                    throw (new ModelNotFoundException())->setModel(AiSession::class, $ids);
                }

                AiMessage::whereIn('ai_session_id', $ids)->delete();
                AiSession::whereKey($ids)->delete();
            });
        } catch (ModelNotFoundException $exception) {
            return JsonResponse::make()
                ->alert()
                ->error('会话不存在或已删除')
                ->send();
        } catch (\Throwable $exception) {
            return JsonResponse::make()
                ->alert()
                ->error($exception->getMessage() ?: '删除失败')
                ->send();
        }

        return JsonResponse::make()
            ->alert()
            ->success('删除成功')
            ->send();
    }

    protected function grid()
    {
        return Grid::make(new AiSession(), function (Grid $grid) {
            $grid->model()
                ->select([
                    'id',
                    'source',
                    'external_id',
                    'title',
                    'workspace_path',
                    'model',
                    'started_at',
                    'message_count',
                    'import_batch_id',
                ])
                ->orderByDesc('started_at')
                ->orderByDesc('id');

            $grid->column('id')->sortable();
            $grid->column('source', '来源')->label([
                'codex' => 'primary',
                'cursor' => 'success',
            ]);
            $grid->column('title', '标题')->display(function ($value) {
                return e(Str::limit($value ?: $this->external_id, 60));
            });
            $grid->column('workspace_path', '工作目录')->display(function ($value) {
                return '<span title="'.e($value).'">'.e(Str::limit($value, 56)).'</span>';
            });
            $grid->column('model', '模型')->display(function ($value) {
                return e($value ?: '-');
            });
            $grid->column('message_count', '消息数')->sortable();
            $grid->column('started_at', '开始时间')->sortable();
            $grid->column('import_batch_id', '批次')->display(function ($value) {
                return $value ? '#'.$value : '-';
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('source', '来源')->select([
                    'codex' => 'codex',
                    'cursor' => 'cursor',
                ]);
                $filter->like('workspace_path', '工作目录');
                $filter->like('model', '模型');
                $filter->between('started_at', '开始时间')->datetime();
                $filter->where('keyword', function ($query) {
                    $keyword = trim((string) $this->input);
                    if ($keyword === '') {
                        return;
                    }

                    $query->where(function ($query) use ($keyword) {
                        $query->where('title', 'like', "%{$keyword}%")
                            ->orWhere('external_id', 'like', "%{$keyword}%")
                            ->orWhereHas('messages', function ($query) use ($keyword) {
                                $query->where('content', 'like', "%{$keyword}%");
                            });
                    });
                }, '标题/正文');
            });

            $grid->disableCreateButton();
        });
    }

    protected function form()
    {
        return Form::make(new AiSession(), function (Form $form) {
            $form->text('title', '标题')
                ->rules('nullable|string|max:512')
                ->attribute('maxlength', 512);
        });
    }

    protected function detail($id)
    {
        $show = new Show($id);

        $show->field('id');
        $show->field('source', '来源');
        $show->field('external_id', '外部ID');
        $show->field('title', '标题');
        $show->field('workspace_path', '工作目录');
        $show->field('model', '模型');
        $show->field('started_at', '开始时间');
        $show->field('ended_at', '结束时间');
        $show->field('message_count', '消息数');
        $show->field('source_path', '来源路径');
        $show->field('metadata', '元数据')->as(function ($value) {
            return '<pre style="white-space:pre-wrap;max-height:260px;overflow:auto;">'.e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)).'</pre>';
        })->unescape();

        $show->disableDeleteButton();

        return $show;
    }

    private function messageTimeline(AiSession $session, Request $request = null)
    {
        $request = $request ?: request();
        $messages = AiMessage::query()
            ->select([
                'id',
                'ai_session_id',
                'seq',
                'role',
                'type',
                'occurred_at',
                'content',
                'tool_name',
            ])
            ->where('ai_session_id', $session->id)
            ->orderBy('seq')
            ->paginate(self::MESSAGES_PER_PAGE, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        $html = '<div class="box box-default"><div class="box-header with-border"><h3 class="box-title">消息时间线</h3>';
        $html .= '<div class="text-muted" style="margin-top:6px;">每页 '.self::MESSAGES_PER_PAGE.' 条，当前 '.$messages->firstItem().'-'.$messages->lastItem().' / '.$messages->total().'</div>';
        $html .= '</div><div class="box-body">';

        foreach ($messages as $message) {
            $role = e($message->role ?: 'unknown');
            $type = e($message->type ?: '');
            $time = $message->occurred_at ? e($message->occurred_at->toDateTimeString()) : '-';
            $tool = $message->tool_name ? ' <span class="label label-warning">'.e($message->tool_name).'</span>' : '';
            $content = e($message->content ?: '');
            $rawUrl = admin_url('ai-sessions/'.$session->id.'/messages/'.$message->id.'/raw');

            $html .= '<div style="border-left:3px solid #d2d6de;margin:0 0 14px 4px;padding:0 0 0 12px;">';
            $html .= '<div><span class="label label-info">'.e($message->seq).'</span> <strong>'.$role.'</strong> <span class="text-muted">'.$type.' '.$time.'</span>'.$tool.'</div>';
            if ($content !== '') {
                $html .= '<pre style="white-space:pre-wrap;margin-top:8px;max-height:360px;overflow:auto;">'.$content.'</pre>';
            }
            $html .= '<a class="btn btn-xs btn-default" target="_blank" href="'.e($rawUrl).'">查看 raw</a>';
            $html .= '</div>';
        }

        $html .= '</div><div class="box-footer">'.$messages->appends($request->except('page'))->links().'</div></div>';

        return $html;
    }

    private function deleteIds($id)
    {
        $ids = request()->input('_key', $id);
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);

        return collect($ids)
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->filter(function ($value) {
                return $value !== '';
            })
            ->unique()
            ->values()
            ->all();
    }

    private function rawMessageHtml(AiSession $session, AiMessage $message)
    {
        $metadata = e(json_encode($message->metadata ?: [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $raw = e(json_encode($message->raw ?: [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $backUrl = admin_url('ai-sessions/'.$session->id);

        $html = '<div class="box box-default"><div class="box-header with-border">';
        $html .= '<h3 class="box-title">消息 #'.e($message->seq).' Raw</h3>';
        $html .= '<div style="margin-top:8px;"><a class="btn btn-sm btn-default" href="'.e($backUrl).'">返回会话</a></div>';
        $html .= '</div><div class="box-body">';
        $html .= '<h4>metadata</h4><pre style="white-space:pre-wrap;max-height:260px;overflow:auto;">'.$metadata.'</pre>';
        $html .= '<h4>raw</h4><pre style="white-space:pre-wrap;max-height:640px;overflow:auto;">'.$raw.'</pre>';
        $html .= '</div></div>';

        return $html;
    }
}
