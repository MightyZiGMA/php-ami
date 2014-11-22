<?php
    /* AMI (asterisk manager interface) functionality
     *
     *  This class implements interactions with asterisk and adds support for
     *  getting and setting a range of data in asterisk using AMI.
     *
     *  @notes:
     *      - All returned arrays contain lower-case keys. This is to make it easier to work with,
     *        as asterisk is using a mix of camel-case, upper-case and lower-case.
     *      - Data retrieved from asterisk can vary between asterisk versions due to changes in AMI and/or asterisk.
     *      - This class is only tested on asterisk 1.6.x and 1.8.x, but will most likely work on
     *        most asterisk versions below asterisk 13.
     *
     *  @version:   1.1
     *  @release-date:  2014-11-22
     *  @author:    Kasper Leth Jensen <kasper.leth.jensen@gmail.com>
     *  @license:   GNU GPLv2.0 <http://www.gnu.org/>
     *
     *  @release-notes:
     *      - 1.0 (2014-11-16) Initial version
     *      - 1.1 (2014-11-22)
     *          - Added new public function get_db() for getting values from astdb
     *          - Added new public function set_db() for setting values from astdb
     *          - Added new argument (bool) $raw to public function sendrecv, to allow return of raw data and no arrays
     *          - Added new private function debug() to allow some debug outs for testing purposes
     *          - Added new public variable (bool) $debug, to control wheater or not to output debug information
     *          - Removed private function error() - replaced with private function debug()
     *          - Removed line in private function sendrecv, that removed the first item in the array of events returned by asterisk - was not used and could lead to unexpected results. If not needed, remove it where it's being received instead
     *          - Fixed syntax of script to be more streamlined
     *
     */
    
    class AMI
    {
        /* Private variables
         */
        
        private $_host = false;
        private $_port = false;
        private $_username = false;
        private $_password = false;
        private $_socket = false;
        private $_socket_errstr = false;
        private $_socket_errno = false;
        private $_socket_timeout = 1; // seconds
        private $_socket_last_return = "";
        private $_socket_line_buffer = 4096;
        private $_ami_actionid = "";
        private $_ami_actionid_counter = 0;
        
        /* Public variables
         */
        
        public $debug = false; // If true, output debug information - for testing only!
        
        /* Constructors
         */
        
        public function __construct( $host = false, $port = false, $username = false, $password = false )
        {
            $this->_host = $host;
            $this->_port = $port;
            $this->_username = $username;
            $this->_password = $password;
            
            if ( !$this->login() )
            {
                return false;
            }
            
            $this->_ami_actionid = uniqid();
            $this->_ami_actionid_counter = time(); // start action id counter from time
        }
        
        public function __destruct()
        {
            $_host = false;
            $_port = false;
            $_username = false;
            $_password = false;
            $_socket = false;
        }
        
        /* Public functions
         */
        
        public function get_sippeer( $sippeers_name = false )
        {
            /* Get all data about a sip peer.
             *
             *  @return values:
             *      - Array of data on success (All peer data is provided by asterisk and can therefore
             *        change from version to version),
             *      - False on failure.
             *
             *  @params:
             *      - (string) sippeers_name: Name of the peer in asterisk (excl. "SIP/").
             *
             */
            
            if ( $sippeers_name === false )
            {
                $this->debug( "get_sippeer( sippeer_name ): No SIP peer specified." );
                return false;
            }
            
            $cmd = array(
                 "Action" => "SIPShowPeer",
                "Peer" => $sippeers_name 
            );
            
            $cmd_return = $this->sendrecv( $cmd );
            
            return $cmd_return;
        }
        
        public function get_sippeers()
        {
            /* Get a list of all peers from asterisk
             *
             *  @return values:
             *      - Array of peers on success. Each peer is represented by an array containing all data of the peer.
             *        Instead of an 0-based array, the keys in the array is the peer name, for easier
             *        manipulating the returned array.
             *      - False on failure.
             *      
             */
            
            $cmd = array(
                 "Action" => "Sippeers" 
            );
            
            $cmd_return = $this->sendrecv( $cmd, "Event: PeerlistComplete\r\n", "PeerEntry" );
            
            $sippeers = array(); // holding all the sip peers
            
            foreach ( $cmd_return as $sippeer )
            {
                $sippeers[$sippeer["objectname"]] = $sippeer;
                unset( $sippeers[$sippeer["objectname"]]["event"] );
                unset( $sippeers[$sippeer["objectname"]]["actionid"] );
            }
            
            ksort( $sippeers ); // sort the array, for normal humans...
            
            return $sippeers;
        }
        
        public function get_queues()
        {
            /* Get a list of all queues in asterisk and their associated members
             *
             *  @return values:
             *      - Array of queues on success. Each queue is represented by an array containing all data of the queue.
             *        Instead of an 0-based array, the keys in the array is the queue name, for easier
             *        manipulating the returned array. Only the key "members" is hard-coded and it contains an array
             *        for each member in the queue and all the associated data for the membership.
             *      - False on failure.
             *      
             */
            
            $cmd = array(
                 "Action" => "QueueStatus" 
            );
            
            $cmd_return = $this->sendrecv( $cmd, "Event: QueueStatusComplete\r\n" );
            
            $queues = array(); // holding all the queues and their data
            $queue_members = array(); // holding all queue members
            
            foreach ( $cmd_return as $queue )
            {
                if ( !isset( $queue["event"] ) )
                {
                    continue;
                }
                
                if ( $queue["event"] == "QueueParams" )
                {
                    if ( !isset( $queues[$queue["queue"]] ) )
                    {
                        $queues[$queue["queue"]] = array();
                        
                        foreach ( $queue as $queue_data_key => $queue_data_value )
                        {
                            if ( $queue_data_key == "event" || $queue_data_key == "actionid" || $queue_data_key == "queue" )
                            {
                                continue; // we don't want to save these two parameters, it makes no sense to keep it.
                            }
                            $queues[$queue["queue"]][$queue_data_key] = $queue_data_value;
                        }
                    }
                } elseif ( $queue["event"] == "QueueMember" )
                {
                    if ( !isset( $queue_members[$queue["queue"]] ) )
                    {
                        $queue_members[$queue["queue"]] = array();
                    }
                    
                    if ( isset( $queue["event"] ) )
                    {
                        unset( $queue["event"] );
                    }
                    if ( isset( $queue["actionid"] ) )
                    {
                        unset( $queue["actionid"] );
                    }
                    
                    $queue_members[$queue["queue"]][$queue["name"]] = $queue;
                }
            }
            
            // Now we have one array containing all the queues and their data,
            // and one array containing all queue members.
            // Let's add the queue members to the queues array...
            
            foreach ( $queues as $queue => $queue_data )
            {
                if ( !isset( $queue_members[$queue] ) )
                {
                    $queue_members[$queue] = array();
                }
                
                ksort( $queue_members[$queue] );
                
                $queues[$queue]["members"] = $queue_members[$queue];
            }
            
            ksort( $queues ); // sort the array, for normal humans...
            
            return $queues;
        }
        
        public function get_db( $family = false, $key = false )
        {
            /* Get value from astdb
             *
             *  @params:
             *       - (string) family: Family in astdb
             *       - (string) key: Key in astdb
             *
             *  @return values:
             *      - String containing the value from astdb
             *      - False on not items found in astdb or on failure.
             *      
             */
            
            if ( $family === false )
            {
                $this->debug( "get_db( family, key ): No family specified." );
                return false;
            }
            
            if ( $key === false )
            {
                $this->debug( "get_db( family, key ): No key specified." );
                return false;
            }
            
            $cmd = array(
                 "Action" => "DBGet",
                "Family" => $family,
                "Key" => $key 
            );
            
            $cmd_return = $this->sendrecv( $cmd, "Event: DBGetComplete\r\n", "DBGetResponse", false );
            
            if ( !isset( $cmd_return[0]["val"] ) ) // remove event key if it exists (it really should, but better safe than sorry...)
            {
                $this->debug( "get_db( family, key ): Action returned no value" );
                return false;
            }
            
            return $cmd_return[0]["val"];
        }
        
        public function set_db( $family = false, $key = false, $value = false )
        {
            /* Set value in astdb
             *
             *  @notes:
             *      - If key/family aready exists, it will overwrite the value silently, otherwise it will be added.
             *
             *  @return values:
             *      - True on success
             *      - False on failure
             *      
             */
            
            $cmd = array(
                "Action" => "DBPut",
                "Family" => $family,
                "Key" => $key,
                "Val" => $value 
            );
            
            $cmd_return = $this->sendrecv( $cmd, false, false, false );
            
            return preg_match( "/updated database successfully/i", $cmd_return["message"] ); // if this string is found in the message reply, the operation went OK.
        }
        
        public function command( $command = false )
        {
            /* Execute command to AMI and return the result
             *
             *  @params_
             *      - (string) command: The command to execute
             *
             *  @return values:
             *      - Result in raw format (not an array)
             *      - False on failure
             *
             */
            
            if ( $command === false )
            {
                $this->debug( "command( command ): No command specified." );
                return false;
            }
            
            $cmd = array(
                 "Action" => "Command",
                "Command" => $command 
            );
            
            $cmd_return = $this->sendrecv( $cmd, "--END COMMAND--\r\n", false, true );
            
            return $cmd_return;
        }
        
        /* Private functions
         */
        
        private function debug( $debugstr = "N/A" )
        {
            /* Output debug information
             *
             *  @params:
             *      - (string) debugstr: String containing the debug message.
             *
             */
            
            if ( $this->debug )
            {
                printf( "[%s] DEBUG: %s\n", get_class( $this ), $debugstr );
            }
            
            return true;
        }
        
        private function login()
        {
            /* Establish connection to asterisk manager interface and log in
             *
             *  @return values:
             *      - True on success
             *      - False on failure
             *
             */
            
            $this->_socket = @fsockopen( $this->_host, $this->_port, $this->_socket_errno, $this->_socket_errstr, $this->_socket_timeout );
            
            if ( $this->_socket === false ) // Could not connect to AMI
            {
                $errmsg = sprintf( "ERROR: Could not connect to AMI: (%s) %s", $this->_socket_errno, $this->_socket_errstr );
                $this->debug( $errmsg );
                return false;
            }
            
            // Connected to AMI - try to log in:
            
            stream_set_timeout( $this->_socket, $this->_socket_timeout );
            
            $cmd = array(
                 "Action" => "Login",
                "Username" => $this->_username,
                "Secret" => $this->_password,
                "Events" => "Off" 
            );
            $cmd_return = $this->sendrecv( $cmd, false, false, false );
            
            if ( isset( $cmd_return["message"] ) && preg_match( "/authentication accepted/i", $cmd_return["message"] ) )
            {
                // logged in - success!
                
                return true;
            } else
            {
                $this->debug( "WARNING: Could not login - authentication failed" );
                fclose( $this->_socket ); // Close the socket, since we cannot use it anyway...
                
                $this->_socket = false;
                
                return false;
            }
        }
        
        private function sendrecv( $cmd_query = false, $event_stop = "\r\n", $event_filter = false, $raw = false )
        {
            /* Sends command to AMI and retrieve the reply/replies (in case of events).
             *
             *  If only param cmd_query is set, the command is executed and everything until the first empty line is returned.
             *  The returned contents is an array containing all parameters from the reply.
             *
             *  If param event_stop is set, the reply is treated as events, and only when a line that matches the value value
             *  is found, the collected replies will be returned.
             *
             *  If param event_filter is set, only replies having the key "event" is retrieved, and only if the value of that is
             *  matching param event_filter.
             *
             *  @return values:
             *      - Array of replies on success. The array will very depending on the arguments supplied.
             *      - False on failure
             *
             *  @params:
             *      - (array) cmd_query:      Array containing the command parameters for AMI.
             *      - (string) event_stop:    String to mark when to stop reading the reply
             *      - (string) event_filter:  Only replies with the key "event" containing this value, will be retrieved.
             *      - (bool) raw:             Return as plain text and not as an array
             *
             */
            
            if ( $this->_socket === false )
            {
                $this->debug( "sendrecv( cmd_query, event_stop, event_filter, raw ): No open socket, cannot send data to AMI." );
                return false;
            }
            
            if ( $cmd_query === false )
            {
                $this->debug( "sendrecv( mixed cmd_query, [ string event_stop, [ string event_filter, [ bool raw] ] ] ): No command specified." );
                return false;
            }
            
            if ( $event_stop === false )
            {
                $event_stop = "\r\n";
            }
            
            $cmd_return = ""; // cached string containing returned text
            
            if ( is_array( $cmd_query ) ) // if cmd_query is an array, we need to generate a plain text packet if the contents
            {
                if ( !isset( $cmd_query["ActionID"] ) )
                {
                    $cmd_query["ActionID"] = $action_id = $this->_ami_actionid . "-" . ( $this->_ami_actionid_counter++ );
                }
                
                $tmp_cmd_query = "";
                foreach ( $cmd_query as $cmd_query_key => $cmd_query_value )
                {
                    $tmp_cmd_query .= $cmd_query_key . ": " . $cmd_query_value . "\r\n";
                }
                $tmp_cmd_query .= "\r\n";
                
                $cmd_query = $tmp_cmd_query;
            }
            else
            {
                return false; // we only accept cmd_query to be array, so we can generate ActionID
            }
            
            fputs( $this->_socket, $cmd_query );
            
            $return_events = array();
            
            while ( $line = fgets( $this->_socket, $this->_socket_line_buffer ) )
            {
                if ( $line == "\r\n" )
                {
                    $this->debug( "sendrecv(): An empty line received, checking what to do now" );
                    if ( $event_stop == "\r\n" ) // we're not waiting for any special packet to arrive, so we're stopping at first empty line
                    {
                        $this->debug( "sendrecv(): event_stop is set to stop at first empty line, so be it." );
                        break;
                    } else // we have a special packet to wait for - save the current content into the array, and carry on receiving.
                    {
                        if ( $raw !== false ) // if raw is set, we want the reply in clear text, not an array
                        {
                            $tmp_packet_arr = $cmd_return;
                        } else
                        {
                            $tmp_packet_arr = $this->packet2array( $cmd_return );
                        }
                        
                        if ( $event_filter !== false && isset( $tmp_packet_arr["event"] ) && $tmp_packet_arr["event"] == $event_filter )
                        {
                            // event_filter has been set, and this packet contains that filter. We want the packet...
                            $this->debug( "sendrecv(): Last packet containg the event we've specified - keep it" );
                            $return_events[] = $tmp_packet_arr;
                        } elseif ( $event_filter === false )
                        {
                            // no event_filter has been set, we want all packets.
                            $this->debug( "sendrecv(): We've specified to keep all packet - keet it" );
                            $return_events[] = $tmp_packet_arr;
                        } else // For debug only
                        {
                            $this->debug( "sendrecv(): The packet we're received doesn't have the right event value - discard it." );
                        }
                        
                        $cmd_return = ""; // reset the buffer, we got a newline, and have saved the previous data (if packet not dropped due to filter)
                    }
                }
                elseif ( $line == $event_stop ) // is line something special we've defined?
                {
                    $this->debug( "sendrecv(): Received an line that was marked as event_stop - stop reading replies from AMI and process the received packets." );
                    break; // stop the loop, we got what we were waiting for, return current packet(s).
                } else
                {
                    $cmd_return .= $line;
                }
                
                $info = stream_get_meta_data( $this->_socket );
                if ( $info["timed_out"] !== false ) // Socket has timed out
                {
                    $this->debug( "Socket timed out - bailing out of read-loop!" );
                    break; // socket timeout, don't proceed
                }
            }
            
            $this->debug( "sendrecv(): Done getting the reply/replies from AMI. Now process the packets" );
            
            if ( count( $return_events ) > 0 ) // we got some event to return instead of single reply
            {
                $this->debug( "sendrecv(): We've gathered some event packets, return then as the result." );
                $this->_socket_last_return = $return_events;
                return $return_events;
            } else
            {
                $this->debug( "sendrecv(): No event packets was gathered, so only one return packet is existing" );
                if ( $raw !== false ) // if raw is set, we want the reply in clear text, not an array
                {
                    $this->debug( "sendrecv(): Raw return format specified - returning reply as it is" );
                    $cmd_return_str = $cmd_return;
                } else
                {
                    $this->debug( "sendrecv(): Convert the gathered reply into a key/value array" );
                    $cmd_return_str = $cmd_return;
                    $cmd_return = $this->packet2array( $cmd_return );
                    
                    if ( isset( $cmd_return["response"] ) )
                    {
                        unset( $cmd_return["response"] );
                    }
                    if ( isset( $cmd_return["actionid"] ) )
                    {
                        unset( $cmd_return["actionid"] );
                    }
                }
                $this->_socket_last_return = $cmd_return;
                
                $this->debug( "sendrecv(): Return the final content" );
                return $cmd_return;
            }
        }
        
        private function packet2array( $packet_str = false )
        {
            /* Convert string to array
             *
             *  @return values:
             *      - Array of key-value on success.
             *      - False on failure
             *
             *  @params:
             *      - string packet_str: String containing lines of key-values.
             *
             *  @notes:
             *      - Empty or invalid lines in packet_str will be ignored.
             *      - Keys can only contain letters, digits and spaces ([a-z0-9\s]+).
             *        If keys is invalid, will be invalid and the line will be ignored.
             *      - keys are always in lower-case to eliminate errors due to inconsistent syntax (camel-case, etc.).
             *      - Values can contain all characters.
             *
             *  @examples:
             *      Input:
             *          Key1: Value1
             *          Key2: Value2
             *          Key3: Value3
             *
             *      Output:
             *          array(
             *            "Key1" => "Value1",
             *            "Key2" => "Value2",
             *            "Key3" => "Value3"
             *          )
             */
            
            if ( $packet_str === false )
            {
                $this->debug( "packet2array(): No packet string specified, cannot do anything without one" );
                return false;
            }
            
            $lines = preg_split( "/\r\n/", $packet_str );
            
            $return_arr = array();
            
            foreach ( $lines as $line )
            {
                if ( preg_match( "/([a-z0-9\s]+):(.*)/i", $line, $matches ) ) // We only want lines containing key-value
                {
                    $key = trim( $matches[1] );
                    $key = strtolower( $key ); // key is always lowercase - this way we don't have to think about CamelCase etc.
                    
                    $value = trim( $matches[2] );
                    
                    $return_arr[$key] = $value;
                }
            }
            
            return $return_arr;
        }
    }
?>