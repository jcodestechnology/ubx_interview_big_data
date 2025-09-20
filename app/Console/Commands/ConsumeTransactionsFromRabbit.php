<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\TransactionImportLog;

class ConsumeTransactionsFromRabbit extends Command
{
    protected $signature = 'rabbitmq:consume-transactions
                            {--queue=transactions}
                            {--prefetch=5}';
    protected $description = 'Consume transaction chunks from RabbitMQ and insert into DB with full logging';

    public function handle()
    {
        $queue = $this->option('queue');
        $prefetch = (int)$this->option('prefetch');

        $connection = new AMQPStreamConnection(
            env('RABBITMQ_HOST','127.0.0.1'),
            env('RABBITMQ_PORT',5672),
            env('RABBITMQ_USER','guest'),
            env('RABBITMQ_PASSWORD','guest'),
            env('RABBITMQ_VHOST','/')
        );

        $channel = $connection->channel();
        $channel->queue_declare($queue, false, true, false, false);
        $channel->basic_qos(0, $prefetch, false);

        $this->info("Listening on queue '{$queue}' (prefetch={$prefetch}) ...");

        $callback = function (AMQPMessage $msg) use ($channel) {
            $startTime = microtime(true);

            $body = $msg->body;
            $payload = json_decode($body, true);

            if (!is_array($payload) || !isset($payload['rows'])) {
                Log::error('Invalid message payload', ['body' => $body]);
                $channel->basic_ack($msg->delivery_info['delivery_tag']);
                return;
            }

            $rows = $payload['rows'];
            $rules = $this->validationRules();

            $validRows = [];
            $logs = [];

            foreach ($rows as $idx => $row) {
                $validator = Validator::make($row, $rules);

                if ($validator->fails()) {
                    $logs[] = [
                        'transaction_id' => $row['transaction_id'] ?? null,
                        'status' => 'failed',
                        'error' => json_encode($validator->errors()->all()),
                        'row_data' => $row,
                        'published_at' => $payload['meta']['published_at'] ?? null,
                        'processed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    continue;
                }

                $clean = $validator->validated();
                if (!empty($clean['transaction_date'])) {
                    $clean['transaction_date'] = date('Y-m-d H:i:s', strtotime($clean['transaction_date']));
                }

                $validRows[] = $clean;

                $logs[] = [
                    'transaction_id' => $clean['transaction_id'] ?? null,
                    'status' => 'success',
                    'error' => null,
                    'row_data' => $clean,
                    'published_at' => $payload['meta']['published_at'] ?? null,
                    'processed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            try {
                // Bulk insert transactions in chunks to avoid memory issues
                $this->bulkInsertTransactions($validRows);

                // Insert logs using Eloquent
                if (!empty($logs)) {
                    foreach (array_chunk($logs, 1000) as $chunk) {
                        TransactionImportLog::insert($chunk);
                    }
                }

                $successCount = count(array_filter($logs, fn($l) => $l['status'] === 'success'));
                $failedCount = count(array_filter($logs, fn($l) => $l['status'] === 'failed'));
                $elapsedTime = round(microtime(true) - $startTime, 2);

                Log::info("Processed ".count($rows)." rows: success={$successCount}, failed={$failedCount}, time={$elapsedTime}s", [
                    'message_meta' => $payload['meta'] ?? null
                ]);

                $channel->basic_ack($msg->delivery_info['delivery_tag']);
            } catch (\Exception $e) {
                Log::error('DB insert/logs failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
                $channel->basic_nack($msg->delivery_info['delivery_tag'], false, false);
            }
        };

        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }

    /**
     * Validation rules for transactions
     */
    protected function validationRules(): array
    {
        return [
            'transaction_id'=>'required|string|max:50',
            'user_id'=>'required|integer',
            'account_number'=>'required|string|max:30', 
            'transaction_date'=>'required|date',
            'transaction_type'=>'required|in:credit,debit',
            'amount'=>'required|numeric',
            'currency'=>'required|string|size:3',
            'status'=>'required|in:pending,completed,failed',
            'merchant_name'=>'nullable|string|max:100',
            'merchant_category'=>'nullable|string|max:50',
            'description'=>'nullable|string|max:255',
            'reference_number'=>'nullable|string|max:50',
            'country'=>'nullable|string|size:2',
            'city'=>'nullable|string|max:50',
            'ip_address'=>'nullable|ip',
            'device'=>'nullable|string|max:50',
            'channel'=>'nullable|in:online,branch,mobile',
            'fee'=>'nullable|numeric',
            'tax'=>'nullable|numeric',
            'balance_before'=>'nullable|numeric',
            'balance_after'=>'nullable|numeric',
            'processing_time_ms'=>'nullable|integer',
            'extra_field1'=>'nullable|string|max:100',
            'extra_field2'=>'nullable|string|max:100',
            'extra_field3'=>'nullable|string|max:100',
            'extra_field4'=>'nullable|string|max:100',
            'extra_field5'=>'nullable|string|max:100',
        ];
    }

    /**
     * Bulk insert transactions in chunks for efficiency
     */
    protected function bulkInsertTransactions(array $rows)
    {
        if (empty($rows)) return;

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }
    }
}
