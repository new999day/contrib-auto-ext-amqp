<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtAmqp;

use AMQPExchange;
use AMQPQueue;
use PhpAmqpLib\Channel\AMQPChannel;
use Composer\InstalledVersions;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

final class ExtAmqpInstrumentation
{
    public const NAME = 'ext_amqp';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.ext_amqp',
            InstalledVersions::getVersion('new999day/opentelemetry-auto-ext-amqp'),
            TraceAttributes::SCHEMA_URL,
        );

        hook(
            AMQPChannel::class,
            'basic_publish',
            pre: static function (
                AMQPChannel $channel,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $payload = isset($params[0]) ? (string)$params[0]->body : '';
                $exchangeName = $params[1];
                $routingKey = $params[2];

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s%s', $exchangeName != '' ? $exchangeName . ' ': '', $routingKey) . ' publish')
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    // code
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    // messaging
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'amqp')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION, 'publish')

                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION, $routingKey)
                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_NAME, $routingKey)
                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_PUBLISH_NAME, sprintf('%s%s', $exchangeName != '' ? $exchangeName . ' ': '', $routingKey))

                    ->setAttribute(TraceAttributes::MESSAGING_RABBITMQ_ROUTING_KEY, $routingKey)
                    ->setAttribute(TraceAttributes::MESSAGING_RABBITMQ_DESTINATION_ROUTING_KEY, $routingKey)
                    ->setAttribute(TraceAttributes::MESSAGING_RABBITMQ_DESTINATION_ROUTING_KEY, $routingKey)
                    ->setAttribute('messaging.message.body', $payload)

                    // network
                    ->setAttribute(TraceAttributes::NET_PROTOCOL_NAME, 'amqp')
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_NAME, 'amqp')
                    ->setAttribute(TraceAttributes::NET_TRANSPORT, 'tcp')
                    ->setAttribute(TraceAttributes::NETWORK_TRANSPORT, 'tcp')
                ;

                $parent = Context::getCurrent();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);

                /**
                 * Inject correlation context into message headers. This will only work if the
                 * method was called with the fourth parameter being supplied, as we can currently not
                 * expand the parameter list in native code.
                 *
                 * @see https://github.com/open-telemetry/opentelemetry-php-instrumentation/issues/68
                 */
                if (4 >= sizeof($params) && array_key_exists(3, $params)) {
                    $attributes = $params[3];

                    if (!array_key_exists('headers', $attributes)) {
                        $attributes['headers'] = [];
                    }

                    $propagator = Globals::propagator();
                    $propagator->inject($attributes['headers'], ArrayAccessGetterSetter::getInstance(), $context);

                    $params[3] = $attributes;
                }

                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                AMQPChannel $channel,
                array $params,
                ?bool $success,
                ?Throwable $exception,
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($success === false) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->end();
            }
        );

        self::createInteractionWithQueueSpan($instrumentation, AMQPQueue::class, 'ack');
        self::createInteractionWithQueueSpan($instrumentation, AMQPQueue::class, 'nack');
        self::createInteractionWithQueueSpan($instrumentation, AMQPQueue::class, 'reject');
    }

    protected static function createInteractionWithQueueSpan(CachedInstrumentation $instrumentation, $class, string $method)
    {
        hook(
            $class,
            $method,
            pre: static function (
                AMQPQueue $queue,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation, $method): array {
                $queueName = $queue->getName();

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($queueName . ' ' . $method)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    // code
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    // messaging
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, 'amqp')
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION, $method)

                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_KIND, 'queue')

                    ->setAttribute(TraceAttributes::MESSAGING_RABBITMQ_ROUTING_KEY, $queueName)
                    ->setAttribute(TraceAttributes::MESSAGING_RABBITMQ_DESTINATION_ROUTING_KEY, $queueName)
                    ->setAttribute(TraceAttributes::MESSAGING_DESTINATION_PUBLISH_NAME, $queueName)

                    ->setAttribute(TraceAttributes::MESSAGING_CLIENT_ID, $queue->getConsumerTag())

                    ->setAttribute(TraceAttributes::NET_PROTOCOL_NAME, 'amqp')
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_NAME, 'amqp')
                    ->setAttribute(TraceAttributes::NET_TRANSPORT, 'tcp')
                    ->setAttribute(TraceAttributes::NETWORK_TRANSPORT, 'tcp')

                    ->setAttribute(TraceAttributes::NET_PEER_NAME, $queue->getChannel()->getConnection()->getHost())
                    ->setAttribute(TraceAttributes::NETWORK_PEER_ADDRESS, $queue->getChannel()->getConnection()->getHost())
                    ->setAttribute(TraceAttributes::NET_PEER_PORT, $queue->getChannel()->getConnection()->getPort())
                    ->setAttribute(TraceAttributes::NETWORK_PEER_PORT, $queue->getChannel()->getConnection()->getPort())
                ;

                $parent = Context::getCurrent();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);

                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                AMQPQueue $queue,
                array $params,
                ?bool $success,
                ?Throwable $exception,
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($success === false) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                if ($exception !== null) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                $span->end();
            }
        );
    }
}
