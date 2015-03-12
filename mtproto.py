# -*- coding: utf-8 -*-
"""
Created on Tue Sep  2 19:26:15 2014

@author: agrigoryev
"""
from binascii import crc32
from datetime import datetime
import io
import json
import socket
import struct


def vis(bs):
    """
    Function to visualize byte streams. Split into bytes, print to console.
    :param bs: BYTE STRING
    """
    symbols_in_one_line = 8
    n = len(bs) // symbols_in_one_line
    for i in range(n):
        print(str(i*symbols_in_one_line)+" | "+" ".join(["%02X" % b for b in bs[i*symbols_in_one_line:(i+1)*symbols_in_one_line]])) # for every 8 symbols line
    print(" ".join(["%02X" % b for b in bs[(i+1)*symbols_in_one_line:]])+"\n") # for last line


class TlConstructor:
    def __init__(self, json_dict):
        self.id = int(json_dict['id'])
        self.type = json_dict['type']
        self.predicate = json_dict['predicate']
        self.params = []
        # case of vector
        for param in json_dict['params']:
            if param['type'] == "Vector<long>":
                param['type'] = "Vector t"
                param['subtype'] = "long"
            else:
                param['subtype'] = None
            self.params.append(param)

class TlMethod:
    def __init__(self, json_dict):
        self.id = int(json_dict['id'])
        self.type = json_dict['type']
        self.method = json_dict['method']
        self.params = json_dict['params']


class TL:
    def __init__(self, filename):
        with open(filename, 'r') as f:
           TL_dict = json.load(f)

        # Read constructors

        self.constructors = TL_dict['constructors']
        self.constructor_id = {}
        self.constructor_type = {}
        for elem in self.constructors:
            z = TlConstructor(elem)
            self.constructor_id[z.id] = z
            self.constructor_type[z.predicate] = z

        self.methods = TL_dict['methods']
        self.method_id = {}
        self.method_name = {}
        for elem in self.methods:
            z = TlMethod(elem)
            self.method_id[z.id] = z
            self.method_name[z.method] = z


## Loading TL_schema
tl = TL("TL_schema.JSON")


def serialize_obj(bytes_io, type_, **kwargs):
    try:
        tl_constructor = tl.constructor_type[type_]
    except KeyError:
        raise Exception("Could not extract type: %s" % type_)
    bytes_io.write(struct.pack('<i', tl_constructor.id))
    for arg in tl_constructor.params:
        serialize_param(bytes_io, type_=arg['type'],  value=kwargs[arg['name']])


def serialize_method(bytes_io, type_, **kwargs):
    try:
        tl_method = tl.method_name[type_]
    except KeyError:
        raise Exception("Could not extract type: %s" % type_)
    bytes_io.write(struct.pack('<i', tl_method.id))
    for arg in tl_method.params:
        serialize_param(bytes_io, type_=arg['type'], value=kwargs[arg['name']])


def serialize_param(bytes_io, type_, value):
    if type_ == "int":
        assert isinstance(value, int)
        bytes_io.write(struct.pack('<i', value))
    elif type_ == "long":
        assert isinstance(value, int)
        bytes_io.write(struct.pack('<q', value))
    elif type_ in ["int128", "int256"]:
        assert isinstance(value, bytes)
        bytes_io.write(value)
    elif type_ == 'string' or 'bytes':
        l = len(value)
        if l < 254: # short string format
            bytes_io.write(int.to_bytes(l, 1, 'little')) # 1 byte of string
            bytes_io.write(value)   # string
            bytes_io.write(b'\x00'*((-l-1) % 4)) # padding bytes
        else:
            bytes_io.write(b'\xfe')  # byte 254
            bytes_io.write(int.to_bytes(l, 3, 'little')) # 3 bytes of string
            bytes_io.write(value) # string
            bytes_io.write(b'\x00'*(-l % 4))  # padding bytes

def deserialize(bytes_io, type_=None, subtype=None):
    """
    :type bytes_io: io.BytesIO object
    """
    assert isinstance(bytes_io, io.BytesIO)

    # Built-in bare types
    if   type_ == 'int':    x = struct.unpack('<i', bytes_io.read(4))[0]
    elif type_ == '#':      x = struct.unpack('<I', bytes_io.read(4))[0]
    elif type_ == 'long':   x = struct.unpack('<q', bytes_io.read(8))[0]
    elif type_ == 'double': x = struct.unpack('<d', bytes_io.read(8))[0]
    elif type_ == 'int128': x = bytes_io.read(16)
    elif type_ == 'int256': x = bytes_io.read(32)
    elif type_ == 'string' or type_ == 'bytes':
        l = int.from_bytes(bytes_io.read(1), 'little')
        assert l <= 254
        if l == 254:
            # We have a long string
            long_len = int.from_bytes(bytes_io.read(3), 'little')
            x = bytes_io.read(long_len)
            bytes_io.read(-long_len % 4)  # skip padding bytes
        else:
            # We have a short string
            x = bytes_io.read(l)
            bytes_io.read(-(l+1) % 4)  # skip padding bytes
        assert isinstance(x, bytes)
    elif type_ == 'vector':
        assert subtype is not None
        count = int.from_bytes(bytes_io.read(4), 'little')
        x = [deserialize(bytes_io, type_=subtype) for i in range(count)]
    else:
        # Boxed types
        i = struct.unpack('<i', bytes_io.read(4))[0]  # read type ID
        try:
            tl_elem = tl.constructor_id[i]
        except KeyError:
            raise Exception("Could not extract type: %s" % type_)
        base_boxed_types = ["Vector t", "Int", "Long", "Double", "String", "Int128", "Int256"]
        if tl_elem.type in base_boxed_types:
            x = deserialize(bytes_io, type_=tl_elem.predicate, subtype=subtype)
        else:  # other types
            x = {}
            for arg in tl_elem.params:
                x[arg['name']] = deserialize(bytes_io, type_=arg['type'], subtype=arg['subtype'])
    return x

class Session:
    """ Manages TCP Transport. encryption and message frames """
    def __init__(self, ip, port):
        # creating socket
        self.sock = socket.socket()
        self.sock.connect((ip, port))
        self.auth_key_id = None
        self.number = 0

    def __del__(self):
        # closing socket when session object is deleted
        self.sock.close()

    @staticmethod
    def header_unencrypted(message):
        """
        Creating header for the unencrypted message:
        :param message: byte string to send
        """
        # Basic instructions: https://core.telegram.org/mtproto/description#unencrypted-message

        # Message id: https://core.telegram.org/mtproto/description#message-identifier-msg-id
        msg_id = int(datetime.utcnow().timestamp()*2**30)*4

        return (b'\x00\x00\x00\x00\x00\x00\x00\x00' +
                struct.pack('<Q', msg_id) +
                struct.pack('<L', len(message)))

    # TCP Transport

    # Instructions may be found here: https://core.telegram.org/mtproto#tcp-transport
    # If a payload (packet) needs to be transmitted from server to client or from client to server,
    # it is encapsulated as follows: 4 length bytes are added at the front (to include the length,
    # the sequence number, and CRC32; always divisible by 4) and 4 bytes with the packet sequence number
    # within this TCP connection (the first packet sent is numbered 0, the next one 1, etc.),
    # and 4 CRC32 bytes at the end (length, sequence number, and payload together).

    def send_message(self, message):
        """
        Forming the message frame and sending message to server
        :param message: byte string to send
        """

        print('>>')
        vis(message)  # Sending message visualisation to console
        data = self.header_unencrypted(message) + message
        step1 = struct.pack('<LL', len(data)+12, self.number) + data
        step2 = step1 + struct.pack('<L', crc32(step1))
        self.sock.send(step2)
        self.number += 1

    def recv_message(self):
        """
        Reading socket and receiving message from server. Check the CRC32 and
        """
        packet_length_data = self.sock.recv(4) # reads how many bytes to read

        if len(packet_length_data) > 0:  # if we have smth. in the socket
            packet_length = struct.unpack("<L", packet_length_data)[0]
            packet = self.sock.recv(packet_length - 4)  # read the rest of bytes from socket
            (self.number, auth_key_id, message_id, message_length)= struct.unpack("<L8s8sI", packet[0:24])
            data = packet[24:24+message_length]
            crc = packet[-4:]

            # Checking the CRC32 correctness of received data
            if crc32(packet_length_data + packet[0:-4]).to_bytes(4, 'little') == crc:
                print('<<')
                vis(data)  # Received message visualisation to console
                return data

    def method_call(self, method, **kwargs):
        z=io.BytesIO()
        serialize_method(z, method, **kwargs)
        self.send_message(z.getvalue())
        server_answer = self.recv_message()
        if server_answer is not None:
            return deserialize(io.BytesIO(server_answer))
        else:
            raise Exception("Server not answered")