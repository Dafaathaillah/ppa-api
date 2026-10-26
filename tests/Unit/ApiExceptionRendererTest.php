<?php

namespace Tests\Unit;

use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Tests\TestCase;

class ApiExceptionRendererTest extends TestCase
{
    public function test_oversized_upload_returns_actionable_json_message(): void
    {
        $response = ApiExceptionRenderer::render(new PostTooLargeException);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Ukuran total foto terlalu besar. Maksimal 128 MB per permintaan.',
        ], $response->getData(true));
    }
}
