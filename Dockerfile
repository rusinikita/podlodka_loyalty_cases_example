FROM maven:3.9-eclipse-temurin-21 AS build
WORKDIR /src
COPY pom.xml ./
RUN mvn -B -q dependency:go-offline
COPY src ./src
RUN mvn -B -q -DskipTests package && cp target/loyalty-*.jar /src/loyalty.jar

FROM eclipse-temurin:21-jre-alpine
RUN adduser -D -u 10001 loyalty
WORKDIR /app
COPY --from=build /src/loyalty.jar /app/loyalty.jar
COPY config ./config
USER loyalty
EXPOSE 8080
ENTRYPOINT ["java", "-jar", "/app/loyalty.jar"]
