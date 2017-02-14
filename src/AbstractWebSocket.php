<?php

namespace Ise\WebSocket;

use Ise\Client\AbstractClient;

abstract class AbstractWebSocket extends AbstractClient
{

    const EOL             = "\r\n";
    const OPCODE_CONTINUE = 0;
    const OPCODE_TEXT     = 1;
    const OPCODE_BINARY   = 2;
    const OPCODE_CLOSE    = 8;
    const OPCODE_PING     = 9;
    const OPCODE_PONG     = 10;
    const OPCODES         = [
        self::OPCODE_CONTINUE,
        self::OPCODE_TEXT,
        self::OPCODE_BINARY,
        self::OPCODE_CLOSE,
        self::OPCODE_PING,
        self::OPCODE_PONG
    ];

    /**
     * @var resource
     */
    protected $socket;

    /**
     * @var boolean
     */
    protected $closing = false;

    /**
     * @var integer
     */
    protected $closingStatus;

    /**
     * @var integer
     */
    protected $lastOpCode;

    /**
     * @var string
     */
    protected $hugePayload;

    /**
     * @var array
     */
    protected $options = [
        'timeout'       => 5,
        'fragment_size' => 4096,
    ];

    /**
     * Set timeout
     *
     * @param integer $timeout
     */
    public function setTimeout($timeout)
    {
        $this->options['timeout'] = (integer) $timeout;

        if (is_resource($this->socket) && get_resource_type($this->socket) === 'stream') {
            stream_set_timeout($this->socket, $this->options['timeout']);
        }
        return $this;
    }

    /**
     * Get timeout
     *
     * @return integer
     */
    public function getTimeout()
    {
        return $this->options['timeout'];
    }

    /**
     * Set fragment size
     *
     * @param integer $size
     * @return self
     */
    public function setFragmentSize($size)
    {
        $this->options['fragment_size'] = (integer) $size;
        return $this;
    }

    /**
     * Get fragment size
     *
     * @return integer
     */
    public function getFragmentSize()
    {
        return $this->options['fragment_size'];
    }

    /**
     * {@inheritDoc}
     * @param integer $opCode
     * @param boolean $masked
     */
    public function send($payload, $opCode = self::OPCODE_TEXT, $masked = true)
    {
        if (!$this->connected) {
            throw new Exception\RuntimeException('Not connected.');
        }

        if (!in_array($opCode, self::OPCODES)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Bad opcode "%s". Try OPCODE_TEXT or OPCODE_BINARY.',
                $opCode
            ));
        }

        // Record the length of the payload
        $length = strlen($payload);
        $cursor = 0;
        $size   = $this->options['fragment_size'];
        // While we have data to send
        while ($length > $cursor) {
            // Get fragment of the payload
            $subPayload = substr($payload, $cursor, $size);

            // Advance cursor
            $cursor += $size;

            // Is this the final fragment?
            $isFinal = $length <= $cursor;

            // Send the fragment
            $this->sendFragment($isFinal, $subPayload, $opCode, $masked);

            // All fragments after the first will be marked a continuation
            $opCode = self::OPCODE_CONTINUE;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function receive()
    {
        if (!$this->connected) {
            throw new Exception\RuntimeException('Not connected.');
        }

        $response = null;
        while ($response === null) {
            $response = $this->receiveFragment();
        }

        return $response;
    }

    /**
     * Tell the socket to close.
     *
     * @param integer $status http://tools.ietf.org/html/rfc6455#section-7.4
     * @param string $message A closing message (max 125 bytes)
     */
    protected function close($status = 1000, $message = 'ttfn')
    {
        $binary = sprintf('%016b', $status);
        $status = '';
        $bytes  = str_split($binary, 8);
        foreach ($bytes as $byte) {
            $status .= chr(bindec($byte));
        }
        $this->send($status . $message, self::OPCODE_CLOSE);

        // Receiving a close frame will close the socket
        $this->closing = true;
        return $this->receive();
    }

    /**
     * Send a fragment
     *
     * @param boolean $isFinal
     * @param string $payload
     * @param integer $opCode
     * @param boolean $masked
     */
    protected function sendFragment($isFinal, $payload, $opCode = self::OPCODE_TEXT, $masked = true)
    {
        // Binary string for header
        $frameHeadBinary = ((bool) $isFinal ? '1' : '0') // Write FIN, final fragment bit
            . '000'                         // RSV 1, 2, 3, [unused]
            . sprintf('%04b', $opCode)      // Opcode
            . ($masked ? '1' : '0');        // Use masking?
        // 7 bits of payload length
        $length = strlen($payload);
        if ($length > 65535) {
            $frameHeadBinary .= decbin(127)
                . sprintf('%064b', $length);
        } elseif ($length > 125) {
            $frameHeadBinary .= decbin(126)
                . sprintf('%016b', $length);
        } else {
            $frameHeadBinary .= sprintf('%07b', $length);
        }

        // Write frame head to frame
        $frame    = '';
        $binaries = str_split($frameHeadBinary, 8);
        foreach ($binaries as $binary) {
            $frame .= chr(bindec($binary));
        }

        // Handle masking
        if ($masked) {
            // Generate a random mask
            $mask = '';
            for ($i = 0; $i < 4; $i++) {
                $mask .= chr(mt_rand(0, 255));
            }
            $frame .= $mask;
        }

        // Append payload to frame
        for ($i = 0; $i < $length; $i++) {
            $frame .= $masked ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        // Write to socket
        $this->write($frame);
    }

    /**
     * Write data to socket
     *
     * @param string $data
     */
    protected function write($data)
    {
        $written = fwrite($this->socket, $data);
        if ($written < strlen($data)) {
            throw new Exception\RuntimeException(sprintf(
                'Could only write %d out of %d bytes.',
                $written,
                strlen($data)
            ));
        }
    }

    /**
     * Receive a fragment
     *
     * @param boolean $allowEmpty
     * @return string
     * @throws RuntimeException
     */
    protected function receiveFragment($allowEmpty = false)
    {
        // Just read the main fragment information first
        $data = $this->read(2, $allowEmpty);
        if (!$data) {
            return '';
        }

        // Get variables from data
        $payload = '';
        $isFinal = (boolean) (ord($data[0]) & 1 << 7); // Bit 0 in byte 0
        $opCode  = (integer) (ord($data[0]) & 31); // Bits 4-7
        $masked  = (boolean) (ord($data[1]) >> 7); // Bit 0 in byte 1
        $length  = (integer) (ord($data[1]) & 127); // Bits 1-7 in byte 1
        // Parse opcode
        if (!in_array($opCode, self::OPCODES)) {
            throw new RuntimeException(sprintf(
                'Bad opcode in websocket frame: %d',
                $opCode
            ));
        }

        // Record if not receiving a continuation
        if ($opCode !== self::OPCODE_CONTINUE) {
            $this->lastOpCode = $opCode;
        }

        // Get payload length
        if ($length > 125) {
            if ($length === 126) {
                $lengthData = $this->read(2); // 126: Payload is a 16-bit unsigned integer
            } else {
                $lengthData = $this->read(8); // 127: Payload is a 64-bit unsigned integer
            }
            $length = bindec(self::sprintb($lengthData));
        }

        // Get masking key
        $maskingKey = $masked ? $this->read(4) : '';

        // Get the actual payload (may be blank, e.g. for close frames)
        if ($length > 0) {
            $payloadData = $this->read($length);
            if ($masked) {
                // Unmask payload
                for ($i = 0; $i < $length; $i++) {
                    $payload .= $payloadData[$i] ^ $maskingKey[$i % 4];
                }
            } else {
                $payload = $payloadData;
            }
        }

        // Check for a ping frame
        if ($opCode === self::OPCODE_PING) {
            $this->send('pong', self::OPCODE_PONG);
        }

        // Check for a close frame
        if ($opCode === self::OPCODE_CLOSE) {
            if ($length >= 2) {
                // Get status and strip from payload
                $binary  = $payload[0] . $payload[1];
                $status  = bindec(sprintf('%08b%08b', ord($payload[0]), ord($payload[1])));
                $payload = substr($payload, 2);

                // Set status
                $this->closingStatus = $status;
                if (!$this->closing) {
                    // Respond to close
                    $this->send($binary . 'Close acknowledged: ' . $status, self::OPCODE_CLOSE);
                }
            }
            if ($this->closing) {
                // Close response, all done
                $this->closing = false;
            }

            // Close the socket
            fclose($this->socket);
            $this->connected = false;
        }

        // If not last fragment, save payload
        if (!$isFinal) {
            $this->hugePayload .= $payload;
            return null;
        } elseif ($this->hugePayload) {
            // Whole payload retrieved
            $payload           = $this->hugePayload . $payload;
            $this->hugePayload = null;
        }

        return $payload;
    }

    /**
     * Read X amount of bytes from the socket
     *
     * @param  integer $length
     * @param  boolean $allowEmpty
     * @return string
     * @throws RuntimeException
     */
    protected function read($length, $allowEmpty = false)
    {
        $data = '';
        while (strlen($data) < $length) {
            $buffer = fread($this->socket, $length - strlen($data));
            if ($buffer === false) {
                $metadata = stream_get_meta_data($this->socket);
                throw new Exception\RuntimeException(sprintf(
                    'Broken frame, read %d of stated %d bytes. Stream state: %s',
                    strlen($data),
                    $length,
                    json_encode($metadata)
                ));
            }
            if ($buffer === '' && !$allowEmpty) {
                $metadata = stream_get_meta_data($this->socket);
                throw new Exception\RuntimeException(sprintf(
                    'Emtpy read; connection dead? Stream state: %s',
                    json_encode($metadata)
                ));
            }
            $data .= $buffer;
        }
        return $data;
    }

    /**
     * Convert binary to string of 1's and 0's
     *
     * @param string $string
     * @return string
     */
    protected function sprintb($string)
    {
        $return = '';
        for ($i = 0, $j = strlen($string); $i < $j; $i++) {
            $return .= sprintf('%08b', ord($string[$i]));
        }

        return $return;
    }
}
