<?php

namespace Lester\EloquentSalesForce\Database;

use Session;
use Log;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Lester\EloquentSalesForce\SalesForceObject;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Lester\EloquentSalesForce\Database\SOQLBuilder as Builder;
use Lester\EloquentSalesForce\Database\SOQLHasMany as HasMany;
use Lester\EloquentSalesForce\Database\SOQLHasOne as HasOne;
use Lester\EloquentSalesForce\Facades\SObjects;
use Illuminate\Support\Collection;

class SOQLBatch extends Collection
{

    public function results($key)
    {
        return $this->get($key);
    }

    public function builder($key)
    {
        return parent::get($key, $this->emptyItem())->builder;
    }

    public function class($key)
    {
        return parent::get($key, $this->emptyItem())->class;
    }

    public function get($key, $default = null)
    {
        return parent::get($key, $this->emptyItem())->results;
    }

    private function emptyItem()
    {
        return (object)[
            'class' => null,
			'builder' => null,
			'results' => collect([]),
        ];
    }

    public function batch(Builder $builder, $tag = null)
    {
        if ($this->count() >= 25) {
			throw new \Exception('You cannot create more than 25 batch queries.');
		}
		$this->put($tag ?: class_basename($builder->getModel()), (object)[
            'class' => $builder->getModel(),
			'builder' => $builder,
			'results' => collect([]),
		]);
		return $builder;
    }

    public function run()
    {
        if ($this->isEmpty()) return $this;

        $version = 'v' . collect(\SObjects::versions())->last()['version'];

		$results = \SObjects::composite('batch', [
            'method' => 'post',
            'body' => [
                'batchRequests' => tap($this->map(function($query) use ($version) {
					return [
						'method' => 'get',
						'url' => $version . '/query?q=' . urlencode($query->builder->toSql()),
					];
				})->values()->toArray(), function($payload) {
					\SObjects::log('SOQL Batch Query', $payload);
				})
            ]
        ]);

        $index = 0;
        foreach ($this as $key => $batch) {
            $batch_result = $results['results'][$index];
            if ($batch_result['statusCode'] != 200) {
                $batch->results = (object)$batch_result;
            } else {
                $batch->results = collect($batch_result['result']['records'])->map(function($item) use ($batch) {
                    $model = $batch->class;
                    return new $model($item);
                });
            }
            $this->put($key, $batch);
            $index++;
        }

        return $this;
    }

}