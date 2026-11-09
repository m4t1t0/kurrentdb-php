package main

import (
	"context"
	"fmt"
	"os"

	"github.com/google/uuid"
	"github.com/kurrent-io/KurrentDB-Client-Go/kurrentdb"
)

func main() {
	connStr := "kurrentdb://kurrentdb:2113?tls=false"
	if v := os.Getenv("KURRENTDB_CONN"); v != "" {
		connStr = v
	}
	settings, err := kurrentdb.ParseConnectionString(connStr)
	if err != nil {
		panic(err)
	}
	client, err := kurrentdb.NewClient(settings)
	if err != nil {
		panic(err)
	}
	result, err := client.AppendToStream(context.Background(), "go-probe", kurrentdb.AppendToStreamOptions{}, kurrentdb.EventData{
		EventID:     uuid.New(),
		EventType:   "order-placed",
		ContentType: kurrentdb.ContentTypeJson,
		Data:        []byte(`{"orderId":42}`),
	})
	if err != nil {
		panic(err)
	}
	fmt.Printf("Go client appended OK: commit=%d prepare=%d nextExpectedVersion=%d\n",
		result.CommitPosition, result.PreparePosition, result.NextExpectedVersion)
}
