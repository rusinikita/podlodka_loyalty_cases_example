FROM golang:1.25-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY cmd ./cmd
COPY internal ./internal
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/loyalty ./cmd/loyalty

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata && adduser -D -u 10001 loyalty
WORKDIR /app
COPY --from=build /out/loyalty /app/loyalty
COPY config ./config
USER loyalty
EXPOSE 8080
ENTRYPOINT ["/app/loyalty"]
