package main

// Raw TCP tap: clients connect on :2114, bytes are mirrored to /dump files,
// forwarded verbatim to kurrentdb:2113. Purpose: byte-diff two clients'
// HTTP/2 exchanges (official Go client vs the PHP spike).

import (
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"sync/atomic"
)

var connCounter atomic.Int64

func main() {
	log.Println("netdump listening on :2114 → kurrentdb:2113")
	listener, err := net.Listen("tcp", ":2114")
	if err != nil {
		panic(err)
	}
	for {
		clientConn, err := listener.Accept()
		if err != nil {
			log.Println("accept:", err)
			continue
		}
		go handle(clientConn)
	}
}

func handle(client net.Conn) {
	id := connCounter.Add(1)
	serverConn, err := net.Dial("tcp", "kurrentdb:2113")
	if err != nil {
		log.Printf("conn %d: dial failed: %v", id, err)
		_ = client.Close()
		return
	}
	log.Printf("conn %d: opened", id)
	c2s := tee(client, serverConn, "client2server", id)
	s2c := tee(serverConn, client, "server2client", id)

	go func() {
		<-c2s
		<-s2c
		_ = client.Close()
		_ = serverConn.Close()
		log.Printf("conn %d: closed", id)
	}()
}

func tee(src, dst net.Conn, tag string, id int64) <-chan struct{} {
	done := make(chan struct{})
	path := fmt.Sprintf("/dump/%s-conn%d.bin", tag, id)
	file, err := os.Create(path)
	if err != nil {
		log.Printf("conn %d: %s dump file: %v", id, tag, err)
	}
	go func() {
		buf := make([]byte, 32*1024)
		for {
			n, readErr := src.Read(buf)
			if n > 0 && file != nil {
				_, _ = file.Write(buf[:n])
			}
			if n > 0 {
				if _, writeErr := dst.Write(buf[:n]); writeErr != nil {
					log.Printf("conn %d: %s write: %v", id, tag, writeErr)
				}
			}
			if readErr == nil {
				continue
			}
			if err, ok := readErr.(*net.OpError); ok && err.Op == "read" && err.Err.Error() == "EOF" {
				// normal half-close
			}
			if file != nil {
				_ = file.Close()
			}
			_ = dst.Close()
			_ = src.Close()
			close(done)

			return
		}
	}()

	return done
}

var _ = io.EOF
