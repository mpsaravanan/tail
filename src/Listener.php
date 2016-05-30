<?php namespace Mookofe\Tail;

use Closure;
use Exception;
use Mookofe\Tail\BaseOptions;
use Illuminate\Config\Repository;
use PhpAmqpLib\Exception\AMQPTimeoutException;


/**
 * Listener Class, used to manage listening between RabbitMQ server
 *
 * @author Victor Cruz <cruzrosario@gmail.com> 
 */
class Listener extends BaseOptions {

    /**
     * Number of messages to be proccessed
     *
     * @var int
     */
    public $message_limit = 0;

    /**
     * Time in seconds the proccess will be running
     *
     * @var int
     */
    public $time = 0;

    /**
     * Time in seconds to kill listening when the queue is empty
     *
     * @var int
     */
    public $empty_queue_timeout = 0;
    
     /**
     * Check the queue is empty
     * Default :0 not check queue empty or not
     * 1 to check Empty 
     * @var int
     */
    public $empty_check = 0;
    
    /**
     * Stop Queue Consume
     * Default :0 continue
     * 1 to stop 
     * @var int
     */
    public $stop_queue = 0;

   /**
     * Listener constructor
     *
     * @param array $options  Options array to get validated
     *
     * @return Mookofe\Tail\Listener
     */
    public function __construct(Repository $config, array $options = NULL)
    {
        parent::__construct($config);

        $this->allowedOptions = array_merge($this->allowedOptions, array('message_limit', 'time', 'empty_queue_timeout', 'empty_check', 'stop_queue'));

        if ($options)
            $this->setOptions($options);
    }

    /**
     * Listen queue server for given queue name
     *
     * @param string $queue_name  Queue name to listen
     * @param array $options  Options to listen
     * @param Closure $closure Function to run for every message
     *
     * @return void
     */
    public function listen($queue_name, array $options = null, Closure $closure)
    {
        $this->queue_name = $queue_name;

        if ($options)
            $this->setOptions($options);        

        $GLOBALS['messages_proccesed'] = 0;
        $GLOBALS['start_time'] = time();

        $connection = new Connection($this->buildConnectionOptions());
        $connection->open();

        $listenerObject = $this;
        //Check Queue Empty Or Not
        $message=$connection->channel->basic_get($this->queue_name, $no_ack = false, $ticket = null);
        if(!empty($listenerObject->empty_check)){
            if(empty($message)){
                echo 0;
                exit();
            }
            else{
                echo 1;
                exit();
            }
        }

        $connection->channel->basic_consume($this->queue_name, $connection->consumer_tag, false, false, false, false, function ($msg) use ($closure, $listenerObject) {

            try
            {
                $closure($msg->body);
            }            
            catch (Exception $e)
            {
                throw $e;
            }

            $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);

            //Update counters
            $GLOBALS['messages_proccesed']++;
            
            //Stop Consumer..
            if(!empty($listenerObject->stop_queue)){
                $msg->delivery_info['channel']->basic_cancel($msg->delivery_info['consumer_tag']);
            }

            //Check if necesary to close consumer      
            if ($listenerObject->message_limit && $GLOBALS['messages_proccesed'] >= $listenerObject->message_limit)
                $msg->delivery_info['channel']->basic_cancel($msg->delivery_info['consumer_tag']);
            
            if ($listenerObject->time && (time()-$GLOBALS['start_time']>= $listenerObject->time))
                $msg->delivery_info['channel']->basic_cancel($msg->delivery_info['consumer_tag']);
        });

        register_shutdown_function(function ($connection) {
            $connection->close();
        }, $connection);

        // Loop as long as the channel has callbacks registered
        try
        {
            while (count($connection->channel->callbacks)) {
                $connection->channel->wait(null, false, $this->empty_queue_timeout);
            }
        }
        catch (AMQPTimeoutException $e)
        {
            return false;
        }
        catch (Exception $e)
        {
            throw $e;
        }        
    }
          
}
